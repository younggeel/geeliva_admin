importScripts('https://www.gstatic.com/firebasejs/8.0.0/firebase-app.js');
importScripts('https://www.gstatic.com/firebasejs/8.0.0/firebase-messaging.js');

@if(!empty($missingKeys))
console.error('[AdminFCM SW] Missing Firebase config keys in .env:', @json($missingKeys));
@endif

firebase.initializeApp({!! $firebaseConfig !!});

const messaging = firebase.messaging();

messaging.setBackgroundMessageHandler(function (payload) {
    const notificationTitle = (payload.notification && payload.notification.title)
        ? payload.notification.title
        : ((payload.data && payload.data.title) ? payload.data.title : 'New notification');
    const notificationOptions = {
        body: (payload.notification && payload.notification.body)
            ? payload.notification.body
            : ((payload.data && payload.data.body) ? payload.data.body : ''),
        data: payload.data || {},
        icon: '/images/logo-light-icon.png',
        badge: '/images/logo-light-icon.png',
    };
    return self.registration.showNotification(notificationTitle, notificationOptions);
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    const data = event.notification.data || {};
    let url = data.click_url || '/orders';
    if (!data.click_url && data.orderId) {
        if (data.type === 'admin_new_parcel') {
            url = '/parcel_orders/edit/' + data.orderId;
        } else {
            url = '/orders/edit/' + data.orderId;
        }
    }
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
            for (let i = 0; i < clientList.length; i++) {
                const client = clientList[i];
                if ('focus' in client) {
                    client.navigate(url);
                    return client.focus();
                }
            }
            if (clients.openWindow) {
                return clients.openWindow(url);
            }
        })
    );
});
