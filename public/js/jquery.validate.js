
var firebaseConfig = {
    apiKey: $.decrypt($.cookie('XSRF-TOKEN-AK')),
    authDomain: $.decrypt($.cookie('XSRF-TOKEN-AD')),
    databaseURL: $.decrypt($.cookie('XSRF-TOKEN-DU')),
    projectId: $.decrypt($.cookie('XSRF-TOKEN-PI')),
    storageBucket: $.decrypt($.cookie('XSRF-TOKEN-SB')),
    messagingSenderId: $.decrypt($.cookie('XSRF-TOKEN-MS')),
    appId: $.decrypt($.cookie('XSRF-TOKEN-AI')),
    measurementId: $.decrypt($.cookie('XSRF-TOKEN-MI'))
}

firebase.initializeApp(firebaseConfig);

// List pages build their query once and call ref.get() again on every
// DataTables draw (page change, sort, each search keystroke), downloading the
// whole collection each time. Re-use the result of the same query object for a
// short time; any write from this page clears the cache so edits show at once.
(function () {
    var QUERY_CACHE_MS = 60 * 1000;
    var cache = new Map();
    var fs = firebase.firestore;
    var originalGet = fs.Query.prototype.get;
    fs.Query.prototype.get = function (options) {
        if (options) {
            return originalGet.call(this, options);
        }
        var query = this;
        var hit = cache.get(query);
        if (hit && Date.now() - hit.at < QUERY_CACHE_MS) {
            return hit.promise;
        }
        var promise = originalGet.call(query);
        cache.set(query, { at: Date.now(), promise: promise });
        promise.catch(function () { cache.delete(query); });
        return promise;
    };
    function clearingCache(proto, name) {
        var original = proto[name];
        proto[name] = function () {
            cache.clear();
            return original.apply(this, arguments);
        };
    }
    clearingCache(fs.DocumentReference.prototype, 'set');
    clearingCache(fs.DocumentReference.prototype, 'update');
    clearingCache(fs.DocumentReference.prototype, 'delete');
    clearingCache(fs.CollectionReference.prototype, 'add');
    clearingCache(fs.WriteBatch.prototype, 'commit');
    clearingCache(fs.Firestore.prototype, 'runTransaction');
})();

// Dashboards only need the number of matching documents. countQuery asks the
// server for a Firestore count (about 1 read per 1,000 documents) instead of
// downloading them. It resolves to an object shaped like a snapshot for
// counting purposes (.size, .empty, .docs.length), so callers keep using
// snapshot.docs.length. If the server count fails it falls back to the old
// download. All counts requested in the same tick go in one request.
var countQuery = (function () {
    var pending = [];
    function snapshotLike(n) {
        return { size: n, empty: n === 0, docs: { length: n } };
    }
    function encode(value) {
        if (value instanceof firebase.firestore.Timestamp) {
            return { __timestamp: value.seconds, nanos: value.nanoseconds };
        }
        if (value instanceof Date) {
            return encode(firebase.firestore.Timestamp.fromDate(value));
        }
        return Array.isArray(value) ? value.map(encode) : value;
    }
    function download(spec) {
        var query = firebase.firestore().collection(spec.collection);
        spec.where.forEach(function (w) { query = query.where(w[0], w[1], w[2]); });
        return query.get().then(function (snapshot) { return snapshotLike(snapshot.docs.length); });
    }
    function flush() {
        var batch = pending;
        pending = [];
        fetch('/dashboard/count', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
            body: JSON.stringify({ queries: batch.map(function (item) {
                return { collection: item.spec.collection, where: item.spec.where.map(function (w) { return [w[0], w[1], encode(w[2])]; }) };
            }) }),
        })
            .then(function (res) { return res.json(); })
            .then(function (body) {
                if (!body.success || !Array.isArray(body.counts)) throw new Error(body.message || 'count failed');
                batch.forEach(function (item, i) { item.resolve(snapshotLike(body.counts[i])); });
            })
            .catch(function (err) {
                console.warn('[countQuery] falling back to download:', err);
                batch.forEach(function (item) { download(item.spec).then(item.resolve, item.reject); });
            });
    }
    return function (collection, where) {
        return new Promise(function (resolve, reject) {
            if (!pending.length) setTimeout(flush, 0);
            pending.push({ spec: { collection: collection, where: where || [] }, resolve: resolve, reject: reject });
        });
    };
})();

// Revenue charts: the rebuildDashboardStats / *OrderStats functions keep one
// document per section per day (Cameroon time) with earnings and commission.
// dashboardEarningsDocs reads those days and returns them shaped like order
// snapshots (one "order" per day carrying the day's totals), so each
// dashboard's existing chart code works unchanged. Until the totals exist it
// asks the server to build them once and uses the old order download.
var dashboardEarningsDocs = (function () {
    var CAMEROON_OFFSET_MS = 60 * 60 * 1000;
    function dayKey(date) {
        return new Date(date.getTime() + CAMEROON_OFFSET_MS).toISOString().slice(0, 10);
    }
    function requestRebuild() {
        try {
            if (sessionStorage.getItem('dashboardStatsRebuildRequested')) return;
            sessionStorage.setItem('dashboardStatsRebuildRequested', '1');
        } catch (e) {}
        firebase.auth().currentUser.getIdToken().then(function (token) {
            return fetch('https://us-central1-' + firebase.app().options.projectId + '.cloudfunctions.net/rebuildDashboardStats', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                body: JSON.stringify({ data: {} })
            });
        }).catch(function (err) { console.warn('[dashboardStats] rebuild request failed', err); });
    }
    function asOrder(collection, stat) {
        if (collection === 'parcel_orders') {
            return { subTotal: stat.earnings, adminCommission: stat.commission, adminCommissionType: 'fixed', createdAt: stat.date };
        }
        return { products: [{ price: stat.earnings, quantity: 1 }], adminCommission: stat.commission, adminCommissionType: 'fixed', createdAt: stat.date };
    }
    return function (collection, sectionId, startTS, endTS, fallbackQuery) {
        var stats = firebase.firestore().collection('dashboard_stats');
        return stats.doc('_meta').get().then(function (meta) {
            if (!meta.exists) {
                requestRebuild();
                return fallbackQuery.get();
            }
            var prefix = collection + '_' + sectionId + '_';
            var id = firebase.firestore.FieldPath.documentId();
            return stats.where(id, '>=', prefix + (startTS ? dayKey(startTS.toDate()) : '0000'))
                .where(id, '<=', prefix + (endTS ? dayKey(endTS.toDate()) : '9999'))
                .get()
                .then(function (snapshot) {
                    return { docs: snapshot.docs.map(function (doc) {
                        var order = asOrder(collection, doc.data());
                        return { id: doc.id, data: function () { return order; } };
                    }) };
                });
        }).catch(function (err) {
            console.warn('[dashboardStats] falling back to order download:', err);
            return fallbackQuery.get();
        });
    };
})();

// Creating a Firebase Auth account signs that auth instance in as the new
// user. Use a separate app so the panel stays signed in with its admin token
// and the writes that follow keep their admin rights.
function createAuthUserKeepingAdmin(email, password) {
    var secondary = firebase.apps.find(function (app) { return app.name === 'account-creation'; })
        || firebase.initializeApp(firebaseConfig, 'account-creation');
    var auth = secondary.auth();
    return auth.setPersistence(firebase.auth.Auth.Persistence.NONE)
        .then(function () { return auth.createUserWithEmailAndPassword(email, password); })
        .then(function (credential) {
            return auth.signOut().then(function () { return credential; });
        });
}

// Firestore Security Rules (tightened 2026-08-08) now require a signed-in Firebase Auth
// user for every read/write. This panel never had one -- it only uses Laravel's own
// session login -- so every Firestore call below this point was failing with
// "Missing or insufficient permissions." Sign in with a short-lived admin custom token
// (minted server-side, see FirebaseController::adminToken -- only issued to someone
// already authenticated through Laravel) before any page script's $(document).ready()
// runs, by holding jQuery's ready queue until sign-in settles (success OR failure --
// it must release either way, or a token-minting problem would freeze the whole panel).
jQuery.holdReady(true);
fetch('/firebase/admin-token', { credentials: 'same-origin' })
    .then(function (res) { return res.json(); })
    .then(function (body) {
        if (!body.success || !body.token) {
            throw new Error(body.message || 'Could not get admin token');
        }
        // Every page load signs in again with a fresh token, so keep the admin
        // session in memory only: nothing survives Laravel logout or closing the tab.
        return firebase.auth().setPersistence(firebase.auth.Auth.Persistence.NONE)
            .then(function () { return firebase.auth().signInWithCustomToken(body.token); });
    })
    .catch(function (err) {
        console.error('[AdminAuth] Firebase sign-in failed -- Firestore reads will be rejected by Security Rules:', err);
    })
    .then(function () {
        jQuery.holdReady(false);
    });