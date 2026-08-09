const admin = require('firebase-admin');
const nodemailer = require('nodemailer');
const fs = require('fs');
const path = require('path');
const serviceAccount = require('./credentials.json');
const projectId = serviceAccount.project_id;

admin.initializeApp({
    credential: admin.credential.cert(serviceAccount),
});

const firestore = admin.firestore();
const messaging = admin.messaging();

// Same inbox as notifyNewOrder.js -- change here if the admin's inbox changes.
const ADMIN_NOTIFY_EMAIL = 'negeexgeel@gmail.com';

/** Tiny .env reader -- avoids adding a dotenv dependency for 6 keys. */
function readEnv(envPath) {
    const values = {};
    const raw = fs.readFileSync(envPath, 'utf8');
    raw.split('\n').forEach((line) => {
        line = line.trim();
        if (!line || line.startsWith('#')) return;
        const idx = line.indexOf('=');
        if (idx === -1) return;
        values[line.slice(0, idx).trim()] = line.slice(idx + 1).trim();
    });
    return values;
}

function buildTransport() {
    const env = readEnv(path.join(__dirname, '../../../.env'));
    return nodemailer.createTransport({
        host: env.MAIL_HOST,
        port: parseInt(env.MAIL_PORT, 10) || 465,
        secure: (env.MAIL_ENCRYPTION || '').toLowerCase() === 'ssl',
        auth: { user: env.MAIL_USERNAME, pass: env.MAIL_PASSWORD },
    });
}

/** Rough total for the notification text only -- not used for any money-moving write.
 *  Mirrors parcelOrderAutoCancel.js::buildParcelTotal so the estimate stays consistent. */
function estimateParcelTotal(order) {
    const discount = parseFloat(order.discount || 0);
    let total = parseFloat(order.subTotal || 0) - discount;

    if (Array.isArray(order.taxSetting)) {
        order.taxSetting.forEach((t) => {
            if (!t.type || !t.tax) return;
            total += t.type === 'percentage' ? (parseFloat(t.tax) * total) / 100 : parseFloat(t.tax);
        });
    }

    return total;
}

/** Same admin_fcm_tokens fan-out pattern as notifyNewOrder.js::notifyAdminsOfNewOrder. */
async function notifyAdminsOfNewParcel(order) {
    const tokensSnapshot = await firestore.collection('admin_fcm_tokens').get();
    if (tokensSnapshot.empty) return;

    const shortOrderId = (order.id || '').slice(-8);
    const senderName = order.sender?.name || 'Unknown sender';
    const receiverName = order.receiver?.name || 'Unknown receiver';
    const total = estimateParcelTotal(order).toFixed(2);

    const notification = {
        title: `New parcel #${shortOrderId}`,
        body: `${senderName} -> ${receiverName} - $${total} - tap to view`,
    };
    const data = {
        type: 'admin_new_parcel',
        orderId: order.id,
        click_url: '/parcels.php',
    };

    await Promise.all(tokensSnapshot.docs.map(async (tokenDoc) => {
        const token = tokenDoc.data()?.fcmToken;
        if (!token) return;
        try {
            await messaging.send({ notification, data, token });
        } catch (err) {
            console.error(`Failed to notify admin token ${tokenDoc.id}:`, err.message);
        }
    }));
}

async function emailAdminOfNewParcel(order, transporter) {
    const shortOrderId = (order.id || '').slice(-8);
    const senderName = order.sender?.name || 'Unknown sender';
    const receiverName = order.receiver?.name || 'Unknown receiver';
    const total = estimateParcelTotal(order).toFixed(2);

    await transporter.sendMail({
        from: 'admin@geeliva.com',
        to: ADMIN_NOTIFY_EMAIL,
        subject: `New parcel #${shortOrderId} from ${senderName}`,
        text: `A new parcel was placed.\n\n` +
            `From: ${senderName} (${order.sender?.phone || 'no phone'})\n` +
            `To: ${receiverName} (${order.receiver?.phone || 'no phone'})\n` +
            `Type: ${order.parcelType || 'n/a'}, Weight: ${order.parcelWeight || 'n/a'}\n` +
            `Estimated total: $${total}\nPayment: ${order.payment_method || 'n/a'}\n\n` +
            `Order ID: ${order.id}`,
    });
}

async function notifyNewParcelCron() {
    try {
        const snapshot = await firestore
            .collection('parcel_orders')
            .where('status', '==', 'Order Placed')
            .get();

        if (snapshot.empty) return;

        let transporter = null;
        try {
            transporter = buildTransport();
        } catch (err) {
            console.error('Could not build mail transport (email step will be skipped):', err.message);
        }

        for (const doc of snapshot.docs) {
            const order = { ...doc.data(), id: doc.id };
            if (order.adminNotified) continue; // dedupe guard, same pattern as notifyNewOrder.js

            const results = await Promise.allSettled([
                notifyAdminsOfNewParcel(order),
                transporter ? emailAdminOfNewParcel(order, transporter) : Promise.resolve(),
            ]);

            results.forEach((r) => {
                if (r.status === 'rejected') {
                    console.error(`notifyNewParcel step failed for parcel ${order.id}:`, r.reason?.message || r.reason);
                }
            });

            await doc.ref.update({ adminNotified: true });
        }
    } catch (error) {
        console.error('Error in notifyNewParcelCron:', error);
    }
}

notifyNewParcelCron();
