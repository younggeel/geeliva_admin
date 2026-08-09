const admin = require('firebase-admin');
const serviceAccount = require('./credentials.json');
const projectId = serviceAccount.project_id;

admin.initializeApp({
    credential: admin.credential.cert(serviceAccount),
    databaseURL: `https://${projectId}-default-rtdb.firebaseio.com`
});

const firestore = admin.firestore();
const messaging = admin.messaging();

const GIFT_ORDER_EXPIRY_HOURS = 48;

/**
 * "Buy for someone" orders (OrderModel.giftRecipientUid set, status "Pending
 * Recipient Confirmation") that the recipient never confirmed within 48h get
 * cancelled and, if paid by wallet, refunded to the PAYER (author) -- mirrors
 * autoCancelOrder.js's wallet-refund pattern, but credits author.id instead of
 * customerId since here the payer and the eventual recipient are different
 * people (author never stops being the payer for gift orders).
 */
async function expireGiftOrdersCron() {
    try {
        const currentTimestamp = admin.firestore.Timestamp.now();
        const batch = firestore.batch();
        const refundPromises = [];

        const pendingSnapshot = await firestore.collection('vendor_orders')
            .where('status', '==', 'Pending Recipient Confirmation')
            .get();

        for (const doc of pendingSnapshot.docs) {
            const order = doc.data();
            if (!order.giftRecipientUid) continue; // safety: only ever touch actual gift orders

            const createdAt = order.createdAt?.toDate?.();
            if (!createdAt) continue;
            const hoursSinceCreated = (currentTimestamp.toMillis() - createdAt.getTime()) / (1000 * 60 * 60);
            if (hoursSinceCreated < GIFT_ORDER_EXPIRY_HOURS) continue;

            console.log(`Expiring unconfirmed gift order: ${doc.id}`);
            batch.update(doc.ref, { status: 'Order Cancelled' });
            refundPromises.push(refundPayer({ ...order, id: doc.id }));
        }

        await batch.commit();
        await Promise.all(refundPromises);
    } catch (error) {
        console.error('Error in expireGiftOrdersCron:', error);
    }
}

async function refundPayer(orderData) {
    try {
        const payerId = orderData.author?.id;
        if (!payerId) return;

        if (orderData.payment_method === 'wallet' || orderData.payment_method === 'Wallet') {
            const payerDoc = await firestore.collection('users').doc(payerId).get();
            if (payerDoc.exists) {
                const payerData = payerDoc.data();
                const totalPrice = computeTotalPrice(orderData);
                const currentWallet = parseFloat(payerData.wallet_amount || 0);
                await payerDoc.ref.update({ wallet_amount: currentWallet + totalPrice });

                const walletId = firestore.collection('tmp').doc().id;
                await firestore.collection('wallet').doc(walletId).set({
                    amount: totalPrice,
                    date: admin.firestore.Timestamp.now(),
                    id: walletId,
                    isTopUp: true,
                    order_id: orderData.id,
                    payment_method: 'Wallet',
                    payment_status: 'success',
                    user_id: payerId,
                    transactionUser: 'customer',
                    note: 'Unconfirmed gift order expired -- refund',
                });
            }
        }

        const payerDoc = await firestore.collection('users').doc(payerId).get();
        const payerFcm = payerDoc.exists ? (payerDoc.data().fcmToken || '') : '';
        if (payerFcm) {
            const shortOrderId = (orderData.id || '').slice(-10);
            await messaging.send({
                notification: {
                    title: 'Gift order expired',
                    body: `Order #${shortOrderId} expired after the recipient didn't confirm within 48 hours. Tap Regenerate to try again.`,
                },
                token: payerFcm,
            });
        }
    } catch (error) {
        console.error('Error in refundPayer:', error);
    }
}

function computeTotalPrice(orderData) {
    let totalPrice = 0;
    const products = orderData.products || [];
    for (const product of products) {
        const quantity = parseInt(product.quantity || 0);
        const price = parseFloat((product.discountPrice && parseFloat(product.discountPrice) != 0) ? product.discountPrice : product.price || 0);
        const extras = parseFloat(product.extras_price || 0);
        totalPrice += (price + extras) * quantity;
    }
    if (!isNaN(orderData.discount)) totalPrice -= parseFloat(orderData.discount);
    if (orderData.specialDiscount?.special_discount) {
        totalPrice -= parseFloat(orderData.specialDiscount.special_discount);
    }
    if (orderData.taxSetting?.length) {
        for (const tax of orderData.taxSetting) {
            const taxVal = tax.type === 'percentage'
                ? (parseFloat(tax.tax) * totalPrice) / 100
                : parseFloat(tax.tax);
            totalPrice += taxVal;
        }
    }
    totalPrice += parseFloat(orderData.deliveryCharge || 0);
    totalPrice += parseFloat(orderData.tip_amount || 0);
    return totalPrice;
}

expireGiftOrdersCron();
