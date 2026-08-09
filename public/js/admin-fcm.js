/**
 * Admin panel Firebase Cloud Messaging (web)
 * - Requests permission via visible "Enable" button (Chrome blocks auto prompts)
 * - Saves FCM token via Laravel backend + Firestore client fallback
 * - Foreground: sound + top snackbar (5s) + click navigates to order
 */
(function (window, document) {
    'use strict';

    var ADMIN_FCM = window.ADMIN_FCM_CONFIG || {};
    var snackbarTimer = null;
    var initStarted = false;

    function log() {
        if (window.console && console.log) {
            var args = Array.prototype.slice.call(arguments);
            args.unshift('[AdminFCM]');
            console.log.apply(console, args);
        }
    }

    function warn() {
        if (window.console && console.warn) {
            var args = Array.prototype.slice.call(arguments);
            args.unshift('[AdminFCM]');
            console.warn.apply(console, args);
        }
    }

    function ensureSnackbarHost() {
        var host = document.getElementById('admin-fcm-snackbar');
        if (host) {
            return host;
        }
        host = document.createElement('div');
        host.id = 'admin-fcm-snackbar';
        host.setAttribute('role', 'alert');
        host.innerHTML =
            '<div class="admin-fcm-snackbar-inner">' +
            '  <div class="admin-fcm-snackbar-text">' +
            '    <strong class="admin-fcm-snackbar-title"></strong>' +
            '    <span class="admin-fcm-snackbar-body"></span>' +
            '  </div>' +
            '  <button type="button" class="admin-fcm-snackbar-open">Open</button>' +
            '  <button type="button" class="admin-fcm-snackbar-close" aria-label="Close">&times;</button>' +
            '</div>';
        document.body.appendChild(host);
        return host;
    }

    function ensureEnableBanner() {
        var host = document.getElementById('admin-fcm-enable-banner');
        if (host) {
            return host;
        }
        host = document.createElement('div');
        host.id = 'admin-fcm-enable-banner';
        host.innerHTML =
            '<div class="admin-fcm-enable-inner">' +
            '  <span class="admin-fcm-enable-text">Enable browser notifications for new orders & parcels</span>' +
            '  <button type="button" class="admin-fcm-enable-btn">Enable notifications</button>' +
            '  <button type="button" class="admin-fcm-enable-dismiss" aria-label="Dismiss">&times;</button>' +
            '</div>';
        document.body.appendChild(host);
        host.querySelector('.admin-fcm-enable-btn').addEventListener('click', function () {
            enableNotificationsFromUserGesture();
        });
        host.querySelector('.admin-fcm-enable-dismiss').addEventListener('click', function () {
            hideEnableBanner();
            try {
                localStorage.setItem('admin_fcm_banner_dismissed', '1');
            } catch (e) {}
        });
        return host;
    }

    function showEnableBanner(message) {
        if (localStorage.getItem('admin_fcm_banner_dismissed') === '1' && !message) {
            return;
        }
        var host = ensureEnableBanner();
        if (message) {
            host.querySelector('.admin-fcm-enable-text').textContent = message;
        }
        host.classList.add('is-visible');
    }

    function hideEnableBanner() {
        var host = document.getElementById('admin-fcm-enable-banner');
        if (host) {
            host.classList.remove('is-visible');
        }
    }

    function hideSnackbar() {
        var host = document.getElementById('admin-fcm-snackbar');
        if (host) {
            host.classList.remove('is-visible');
        }
        if (snackbarTimer) {
            clearTimeout(snackbarTimer);
            snackbarTimer = null;
        }
    }

    function resolveClickUrl(data) {
        data = data || {};
        if (data.click_url) {
            return data.click_url;
        }
        if (data.orderId) {
            if (data.type === 'admin_new_parcel') {
                return '/parcel_orders/edit/' + data.orderId;
            }
            return '/orders/edit/' + data.orderId;
        }
        return '/orders';
    }

    function showSnackbar(title, body, clickUrl) {
        var host = ensureSnackbarHost();
        host.querySelector('.admin-fcm-snackbar-title').textContent = title || 'Notification';
        host.querySelector('.admin-fcm-snackbar-body').textContent = body || '';
        host.classList.add('is-visible');

        var navigate = function () {
            hideSnackbar();
            window.location.href = clickUrl;
        };

        host.querySelector('.admin-fcm-snackbar-open').onclick = navigate;
        host.querySelector('.admin-fcm-snackbar-inner').onclick = function (e) {
            if (e.target.classList.contains('admin-fcm-snackbar-close')) {
                hideSnackbar();
                return;
            }
            if (!e.target.classList.contains('admin-fcm-snackbar-open')) {
                navigate();
            }
        };
        host.querySelector('.admin-fcm-snackbar-close').onclick = function (e) {
            e.stopPropagation();
            hideSnackbar();
        };

        if (snackbarTimer) {
            clearTimeout(snackbarTimer);
        }
        snackbarTimer = setTimeout(hideSnackbar, 5000);
    }

    function playNotifySound() {
        try {
            var audio = new Audio(ADMIN_FCM.soundUrl || '/sounds/admin_notify.wav');
            audio.volume = 0.85;
            var playPromise = audio.play();
            if (playPromise && typeof playPromise.catch === 'function') {
                playPromise.catch(function () {
                    playBeepFallback();
                });
            }
        } catch (e) {
            playBeepFallback();
        }
    }

    function playBeepFallback() {
        try {
            var Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) {
                return;
            }
            var ctx = new Ctx();
            var osc = ctx.createOscillator();
            var gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.value = 880;
            gain.gain.value = 0.15;
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start();
            setTimeout(function () {
                osc.frequency.value = 1175;
            }, 120);
            setTimeout(function () {
                osc.stop();
                ctx.close();
            }, 320);
        } catch (e) {
            // ignore
        }
    }

    function handleForegroundMessage(payload) {
        var data = payload.data || {};
        var title = (payload.notification && payload.notification.title) || data.title || 'New notification';
        var body = (payload.notification && payload.notification.body) || data.body || '';
        var clickUrl = resolveClickUrl(data);
        playNotifySound();
        showSnackbar(title, body, clickUrl);
    }

    function saveTokenToBackend(token) {
        if (!token || !ADMIN_FCM.saveUrl) {
            return Promise.resolve(false);
        }
        return fetch(ADMIN_FCM.saveUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': ADMIN_FCM.csrfToken || '',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin',
            body: JSON.stringify({ fcmToken: token })
        }).then(function (res) {
            return res.json().then(function (body) {
                if (!res.ok || !body.success) {
                    warn('Backend token save failed', body);
                    return false;
                }
                log('Token saved via backend', body.userId || '');
                return true;
            });
        }).catch(function (err) {
            warn('Backend token save error', err);
            return false;
        });
    }

    function saveTokenToFirestore(token) {
        if (!token || !window.firebase || !ADMIN_FCM.userId) {
            return Promise.resolve(false);
        }
        try {
            var database = firebase.firestore();
            return database.collection('admin_fcm_tokens').doc(String(ADMIN_FCM.userId)).set({
                userId: String(ADMIN_FCM.userId),
                email: ADMIN_FCM.email || '',
                name: ADMIN_FCM.name || '',
                fcmToken: token,
                platform: 'web',
                updatedAt: firebase.firestore.FieldValue.serverTimestamp()
            }, { merge: true }).then(function () {
                log('Token saved via Firestore client');
                return true;
            }).catch(function (err) {
                warn('Firestore client save failed', err);
                return false;
            });
        } catch (e) {
            warn('Firestore client save exception', e);
            return Promise.resolve(false);
        }
    }

    function saveToken(token) {
        if (!token) {
            return;
        }
        saveTokenToBackend(token).then(function (ok) {
            if (!ok) {
                return saveTokenToFirestore(token);
            }
            return true;
        }).then(function (ok) {
            if (ok) {
                hideEnableBanner();
                try {
                    localStorage.setItem('admin_fcm_token_ready', '1');
                } catch (e) {}
            } else {
                showEnableBanner('Token created but could not save to Firebase. Check credentials.json / Firestore rules.');
            }
        });
    }

    async function registerServiceWorker() {
        var registration = await navigator.serviceWorker.register('/firebase-messaging-sw.js', { scope: '/' });
        await navigator.serviceWorker.ready;
        return registration;
    }

    async function getMessagingToken(registration) {
        var messaging = firebase.messaging();
        if (typeof messaging.useServiceWorker === 'function' && registration) {
            try {
                messaging.useServiceWorker(registration);
            } catch (e) {
                // Already registered / not needed on newer SDK builds
            }
        }

        var options = { vapidKey: ADMIN_FCM.vapidKey };
        if (registration) {
            options.serviceWorkerRegistration = registration;
        }
        return messaging.getToken(options);
    }

    async function setupMessagingListeners() {
        var messaging = firebase.messaging();
        messaging.onMessage(function (payload) {
            handleForegroundMessage(payload || {});
        });
        if (typeof messaging.onTokenRefresh === 'function') {
            messaging.onTokenRefresh(function () {
                getMessagingToken(null).then(saveToken).catch(function (err) {
                    warn('Token refresh failed', err);
                });
            });
        }
    }

    async function requestAndRegister() {
        if (!window.firebase || !firebase.messaging) {
            showEnableBanner('Firebase messaging SDK not loaded. Hard refresh the page.');
            return false;
        }
        if (!ADMIN_FCM.vapidKey) {
            showEnableBanner('FIREBASE_VAPID_KEY is missing in server .env — add the Web Push key pair (public).');
            warn('FIREBASE_VAPID_KEY is missing');
            return false;
        }
        if (!('Notification' in window) || !('serviceWorker' in navigator)) {
            showEnableBanner('This browser does not support web push notifications.');
            return false;
        }

        var permission = Notification.permission;
        if (permission !== 'granted') {
            permission = await Notification.requestPermission();
        }
        if (permission !== 'granted') {
            showEnableBanner('Notifications are blocked. Click the lock icon in the address bar → allow Notifications, then click Enable again.');
            warn('Notification permission not granted:', permission);
            return false;
        }

        try {
            var registration = await registerServiceWorker();
            var token = await getMessagingToken(registration);
            if (!token) {
                showEnableBanner('Could not get FCM token. Check Firebase Web Push setup.');
                return false;
            }
            log('Got FCM token');
            saveToken(token);
            await setupMessagingListeners();
            return true;
        } catch (err) {
            warn('register failed', err);
            showEnableBanner('Failed to enable notifications: ' + (err && err.message ? err.message : 'see console'));
            return false;
        }
    }

    function enableNotificationsFromUserGesture() {
        try {
            localStorage.removeItem('admin_fcm_banner_dismissed');
        } catch (e) {}
        requestAndRegister();
    }

    async function initAdminFcm() {
        if (initStarted) {
            return;
        }
        initStarted = true;

        log('init', {
            hasFirebase: !!window.firebase,
            hasMessaging: !!(window.firebase && firebase.messaging),
            hasUser: !!ADMIN_FCM.userId,
            hasVapid: !!ADMIN_FCM.vapidKey,
            permission: (typeof Notification !== 'undefined') ? Notification.permission : 'n/a'
        });

        if (!ADMIN_FCM.userId) {
            warn('No authenticated admin user id in ADMIN_FCM_CONFIG');
            return;
        }

        if (!ADMIN_FCM.vapidKey) {
            showEnableBanner('FIREBASE_VAPID_KEY is missing in server .env — add the Web Push key pair (public).');
            return;
        }

        if (typeof Notification === 'undefined') {
            return;
        }

        // Chrome often blocks auto permission prompts — show clickable banner first.
        if (Notification.permission === 'granted') {
            await requestAndRegister();
            return;
        }

        showEnableBanner();
    }

    window.AdminFcm = {
        init: initAdminFcm,
        enable: enableNotificationsFromUserGesture,
        showSnackbar: showSnackbar,
        playNotifySound: playNotifySound
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            setTimeout(initAdminFcm, 1000);
        });
    } else {
        setTimeout(initAdminFcm, 1000);
    }
})(window, document);
