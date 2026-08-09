
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
        return firebase.auth().signInWithCustomToken(body.token);
    })
    .catch(function (err) {
        console.error('[AdminAuth] Firebase sign-in failed -- Firestore reads will be rejected by Security Rules:', err);
    })
    .then(function () {
        jQuery.holdReady(false);
    });