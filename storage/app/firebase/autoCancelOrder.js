const admin = require('firebase-admin');
const serviceAccount = require('./credentials.json');
const projectId = serviceAccount.project_id;

admin.initializeApp({
    credential: admin.credential.cert(serviceAccount),
    databaseURL: `https://${projectId}-default-rtdb.firebaseio.com`
});

const firestore = admin.firestore();
const messaging = admin.messaging();

async function autoCancelOrderCron() {
    try {
        const currentTimestamp = admin.firestore.Timestamp.now();

        const timingSnapshot = await firestore.collection('settings').doc("DriverNearBy").get();
        if (!timingSnapshot.exists) {
            console.log("No notification timing settings found.");
            return;
        }

        const timingData = timingSnapshot.data();
        const orderAutoCancelDuration = timingData.orderAutoCancelDuration || 5;
        const batch = firestore.batch();
        const refundPromises = [];

        // Store/vendor never accepted the order within orderAutoCancelDuration
        // minutes of it being placed -- cancel + refund + notify. Applies to
        // every vendor order type now (previously skipped ecommerce, back when
        // ecommerce orders never went through this accept flow at all).
        const orderPlacedSnapshot = await firestore.collection('vendor_orders').where('status', '==', 'Order Placed').get();
        for (const doc of orderPlacedSnapshot.docs) {
            const order=doc.data();
            const createdAt = order.createdAt;
            console.log('order scheduleTime--->'+order.scheduleTime)

            const scheduleTime = order.scheduleTime?.toDate?.();
            const baseTime = scheduleTime || createdAt.toDate();
            const expirationTime = new Date(baseTime.getTime() + orderAutoCancelDuration * 60000);
            const expirationTimestamp = admin.firestore.Timestamp.fromDate(expirationTime);

            if (currentTimestamp.toMillis() > expirationTimestamp.toMillis()) {
                console.log(`Cancelling order: ${doc.id}`);
                batch.update(doc.ref, { status: "Order Cancelled" });
                refundPromises.push(getCustomerRefund({ ...order, id: doc.id }));
                refundPromises.push(notifyAdmins({ ...order, id: doc.id }, 'store_not_accepted'));
            }
        }

        await batch.commit();
        await Promise.all(refundPromises);

        // Store/vendor DID accept the order, but no driver has claimed it within
        // DRIVER_UNCLAIMED_NOTIFY_HOURS. Unlike the block above, this does NOT
        // cancel anything -- just alerts the admin (via admin_fcm_tokens, the
        // same collection the admin web panel registers its own push token
        // into) so they can step in manually. `adminNotifiedStale` guards
        // against re-notifying every minute for the same still-unclaimed order.
        const DRIVER_UNCLAIMED_NOTIFY_HOURS = 24;
        const staleAcceptedSnapshot = await firestore
            .collection('vendor_orders')
            .where('status', '==', 'Order Accepted')
            .get();

        for (const doc of staleAcceptedSnapshot.docs) {
            const order = doc.data();
            if (!order || order.adminNotifiedStale) continue;
            if (order.driverID) continue; // already claimed by a driver

            const createdAt = order.createdAt?.toDate?.();
            if (!createdAt) continue;
            const hoursSinceCreated = (currentTimestamp.toMillis() - createdAt.getTime()) / (1000 * 60 * 60);
            if (hoursSinceCreated < DRIVER_UNCLAIMED_NOTIFY_HOURS) continue;

            console.log(`Notifying admin: order ${doc.id} unclaimed by any driver for 24h+`);
            await notifyAdmins({ ...order, id: doc.id }, 'driver_not_responded');
            await doc.ref.update({ adminNotifiedStale: true });
        }

    } catch (error) {
        console.error("Error in autoCancelOrderCron:", error);
    }
}

/**
 * Push a notification to every admin who has a registered web FCM token
 * (admin_fcm_tokens, written by AdminFcmController::saveToken). Best-effort
 * per token -- one stale/invalid token shouldn't stop the others from
 * getting notified.
 */
async function notifyAdmins(orderData, reason) {
    try {
        const tokensSnapshot = await firestore.collection('admin_fcm_tokens').get();
        if (tokensSnapshot.empty) return;

        const shortOrderId = (orderData.id || '').slice(-10);
        const vendorName = orderData.vendor?.title || 'Unknown vendor';

        const messagesByReason = {
            store_not_accepted: {
                title: 'Order auto-cancelled - store did not respond',
                body: `Order #${shortOrderId} from ${vendorName} was cancelled after the store did not accept it in time.`,
            },
            driver_not_responded: {
                title: 'Order needs a driver',
                body: `Order #${shortOrderId} from ${vendorName} has been waiting 24+ hours with no driver response.`,
            },
        };
        const notification = messagesByReason[reason];
        if (!notification) return;

        await Promise.all(tokensSnapshot.docs.map(async (tokenDoc) => {
            const token = tokenDoc.data()?.fcmToken;
            if (!token) return;
            try {
                await messaging.send({ notification, token });
            } catch (err) {
                console.error(`Failed to notify admin token ${tokenDoc.id}:`, err.message);
            }
        }));
    } catch (error) {
        console.error("Error in notifyAdmins:", error);
    }
}

async function getRefund(orderData) {
    try {
        const vendorId = orderData.vendor?.author;
        const customerId = orderData.author?.id;
        let vendorAmount = 0, deliveryCharge = 0, tipAmount = 0, customerAmount = 0;
        let vendorFcm = '', customerFcm = '';
        let vendorTaxAmount=0;
        let vendorBaseAmount=0;
        const walletSnapshot = await firestore.collection('wallet')
            .where('user_id', '==', vendorId)
            .where('order_id', '==', orderData.id)
            .where('isTopUp', '==', true)
            .get();

        for (const doc of walletSnapshot.docs) {
            const data=doc.data();
            if(data.payment_method=='tax') {
                vendorTaxAmount=parseFloat(data.amount);
            } else {
                vendorBaseAmount=parseFloat(data.amount);
            }
            vendorAmount += parseFloat(data.amount || 0);
        }

        if (vendorAmount) {
            const vendorDoc = await firestore.collection('users').doc(vendorId).get();
            if (vendorDoc.exists) {
                const vendorData = vendorDoc.data();
                vendorFcm = vendorData.fcmToken || '';
                const vendorWallet = parseFloat(vendorData.wallet_amount || 0);
                await vendorDoc.ref.update({ wallet_amount: vendorWallet - vendorAmount });
            }

            const walletId = firestore.collection("tmp").doc().id;
            await firestore.collection('wallet').doc(walletId).set({
                amount: vendorBaseAmount,
                date: admin.firestore.Timestamp.now(),
                id: walletId,
                isTopUp: false,
                order_id: orderData.id,
                payment_method: "Wallet",
                payment_status: 'success',
                user_id: vendorId,
                transactionUser: 'vendor',
                note: 'Order amount refunded to customer'
            });
            const walletTaxId = firestore.collection("tmp").doc().id;
             await firestore.collection('wallet').doc(walletTaxId).set({
                amount: vendorTaxAmount,
                date: admin.firestore.Timestamp.now(),
                id: walletTaxId,
                isTopUp: false,
                order_id: orderData.id,
                payment_method: "tax",
                payment_status: 'success',
                user_id: vendorId,
                transactionUser: 'vendor',
                note: 'Order tax refunded to customer'
            });
        }

        if (orderData.payment_method != 'cod') {
            getCustomerRefund(orderData);
        } else {
            const customerDoc = await firestore.collection('users').doc(customerId).get();
            if (customerDoc.exists) {
                customerFcm = customerDoc.data().fcmToken || '';
            }
        }
        await removeRedeemCashback(orderData);
        await sendNotification(vendorFcm, customerFcm, orderData, 'no driver found');

    } catch (error) {
        console.error("Error in getRefund:", error);
    }
}

async function getCustomerRefund(orderData) {
    try {
        const customerId=orderData.author.id;
        const vendorId = orderData.vendor?.author;
        let totalPrice = 0;
        let customerFcm = '';
        const products = orderData.products || [];

        for (const product of products) {
            const quantity = parseInt(product.quantity || 0);
            const price = parseFloat((product.discountPrice && parseFloat(product.discountPrice) != 0) ? product.discountPrice : product.price || 0);
            const extras = parseFloat(product.extras_price  || 0);
            totalPrice += (price + extras) * quantity;
        }
        if (!isNaN(orderData.discount)) totalPrice -= parseFloat(orderData.discount);
        if (orderData.specialDiscount?.special_discount) {
            totalPrice -= parseFloat(orderData.specialDiscount.special_discount);
        }
        var totalTax=0;
        if (orderData.taxSetting?.length) {
            for (const tax of orderData.taxSetting) {
                const taxVal = tax.type === 'percentage'
                    ? (parseFloat(tax.tax) * totalPrice) / 100
                    : parseFloat(tax.tax);
                totalTax+=taxVal;
            }
        }
        totalPrice+=parseFloat(totalTax);
        totalPrice += parseFloat(orderData.deliveryCharge || 0);
        totalPrice+=parseFloat(orderData.tip_amount||0);
        const customerDoc = await firestore.collection('users').doc(customerId).get();
        if (customerDoc.exists) {
            const customerData = customerDoc.data();
            customerFcm = customerData.fcmToken || '';
               if (orderData.payment_method != 'cod') {
                const customerWallet = parseFloat(customerData.wallet_amount || 0);
                await customerDoc.ref.update({ wallet_amount: customerWallet + totalPrice });

                const walletId = firestore.collection("tmp").doc().id;
                await firestore.collection('wallet').doc(walletId).set({
                    amount: totalPrice,
                    date: admin.firestore.Timestamp.now(),
                    id: walletId,
                    isTopUp: true,
                    order_id: orderData.id,
                    payment_method: "Wallet",
                    payment_status: 'success',
                    user_id: customerId,
                    transactionUser: 'customer',
                    note: 'Order amount refund'
                });
            }
        }

 
        const vendorDoc = await firestore.collection('users').doc(vendorId).get();
        if(vendorDoc.exists) {
            const vendorData=vendorDoc.data();
            vendorFcm=vendorData.fcmToken||'';
        }
        await removeRedeemCashback(orderData);
        await sendNotification(vendorFcm, customerFcm, orderData, 'not accepted');

    } catch (error) {
        console.error("Error in getCustomerRefund:", error);
    }
}

async function sendNotification(vendorFcm,customerFcm,orderData,type) {
    const shortOrderId = orderData.id.slice(-10);
    const vendorMessage = {
        title: 'Order Cancelled',
        body: type === 'no driver found'
            ? `Order #${shortOrderId} has been cancelled due to no driver available.`
            : `Order #${shortOrderId} has been cancelled due to delay in accepting the order.`
    };

    const customerMessage = {
        title: 'Order Cancelled',
        body: type === 'no driver found'
            ? `Order #${shortOrderId} has been cancelled due to no driver available.`
            : `Order #${shortOrderId} has been cancelled due to restaurant did not accept the order.`
    };

    try {
        if (vendorFcm) {
            await messaging.send({ notification: vendorMessage, token: vendorFcm });
            console.log("Notification sent to vendor");
        }

        if (customerFcm) {
            await messaging.send({ notification: customerMessage, token: customerFcm });
            console.log("Notification sent to customer");
        }

    } catch (error) {
        console.error("Error sending notifications:", error);
    }
}
async function removeRedeemCashback(orderData) {
    const snapshot = await firestore.collection('cashback_redeem')
        .where('orderId', '==', orderData.id)
        .limit(1)
        .get();
    if (!snapshot.empty) {
        const docId = snapshot.docs[0].id;
        await firestore.collection('cashback_redeem').doc(docId).delete();
    }
}
autoCancelOrderCron();
