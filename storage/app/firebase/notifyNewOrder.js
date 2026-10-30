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

// Where the "new order" email goes. Change this if the admin's inbox changes -- this
// script is deployed independently of admin2, so it doesn't read admin2's config file.
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

/** Rough total for the notification text only -- not used for any money-moving write. */
function estimateOrderTotal(order) {
    let total = 0;
    (order.products || []).forEach((p) => {
        const qty = parseInt(p.quantity || 0, 10);
        total += parseFloat(p.price || 0) * qty;
        if (p.extras && p.extras.length) {
            total += parseFloat(p.extras_price || 0) * qty;
        }
    });
    total += parseFloat(order.deliveryCharge || 0);
    return total;
}

/** Same admin_fcm_tokens fan-out pattern as autoCancelOrder.js::notifyAdmins. */
async function notifyAdminsOfNewOrder(order) {
    const tokensSnapshot = await firestore.collection('admin_fcm_tokens').get();
    if (tokensSnapshot.empty) return;

    const shortOrderId = (order.id || '').slice(-8);
    const vendorName = order.vendor?.title || 'Unknown vendor';
    const total = estimateOrderTotal(order).toFixed(2);

    const notification = {
        title: `New order #${shortOrderId}`,
        body: `${vendorName} — $${total} — tap to view`,
    };
    const data = {
        type: 'admin_new_order',
        orderId: order.id,
        click_url: '/index.php',
    };
    // High-priority, sticky, vibrating system notification so the phone plays its
    // notification sound even with the browser in the background. Keep in sync with
    // admin2/src/Fcm.php::adminAlertWebpush (which admin2's "Test alert" button uses).
    const webpush = {
        headers: { Urgency: 'high', TTL: '3600' },
        notification: {
            icon: '/assets/icons/icon-192.png',
            badge: '/assets/icons/icon-192.png',
            tag: `order-${order.id}`,
            renotify: true,
            requireInteraction: true,
            silent: false,
            vibrate: [500, 200, 500, 200, 500, 200, 500],
        },
    };

    await Promise.all(tokensSnapshot.docs.map(async (tokenDoc) => {
        const token = tokenDoc.data()?.fcmToken;
        if (!token) return;
        try {
            await messaging.send({ notification, data, webpush, token });
        } catch (err) {
            console.error(`Failed to notify admin token ${tokenDoc.id}:`, err.message);
        }
    }));
}

async function emailAdminOfNewOrder(order, transporter) {
    const shortOrderId = (order.id || '').slice(-8);
    const vendorName = order.vendor?.title || 'Unknown vendor';
    const total = estimateOrderTotal(order).toFixed(2);
    const productLines = (order.products || [])
        .map((p) => `  - ${p.quantity || 1} x ${p.name || 'item'}`)
        .join('\n');

    await transporter.sendMail({
        from: 'admin@geeliva.com',
        to: ADMIN_NOTIFY_EMAIL,
        subject: `New order #${shortOrderId} from ${vendorName}`,
        text: `A new order was placed.\n\nStore: ${vendorName}\nEstimated total: $${total}\nPayment: ${order.payment_method || 'n/a'}\n\nItems:\n${productLines}\n\nOrder ID: ${order.id}`,
    });
}

async function notifyNewOrderCron() {
    try {
        const snapshot = await firestore
            .collection('vendor_orders')
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
            if (order.adminNotified) continue; // dedupe guard, same pattern as adminNotifiedStale

            const results = await Promise.allSettled([
                notifyAdminsOfNewOrder(order),
                transporter ? emailAdminOfNewOrder(order, transporter) : Promise.resolve(),
            ]);

            results.forEach((r) => {
                if (r.status === 'rejected') {
                    console.error(`notifyNewOrder step failed for order ${order.id}:`, r.reason?.message || r.reason);
                }
            });

            // Mark notified regardless of individual step failures (best-effort, matches
            // notifyAdmins' philosophy elsewhere) -- avoids retrying forever on one bad token/email.
            await doc.ref.update({ adminNotified: true });
        }
    } catch (error) {
        console.error('Error in notifyNewOrderCron:', error);
    }
}

notifyNewOrderCron();
