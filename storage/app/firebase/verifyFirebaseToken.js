// Verifies a Firebase Auth ID token server-side and prints the decoded claims as JSON.
// Invoked by App\Http\Controllers\Api\NotificationController so mobile clients can prove
// who they are without ever holding a service-account credential themselves.
//
// Usage: node verifyFirebaseToken.js <path-to-json-file-containing-{"idToken":"..."}>
// Exit 0 + JSON claims on stdout = valid. Exit 1 + {"error":...} on stdout = invalid/expired/revoked.
const fs = require('fs');
const admin = require('firebase-admin');
const serviceAccount = require('./credentials.json');

admin.initializeApp({ credential: admin.credential.cert(serviceAccount) });

async function main() {
  const inputPath = process.argv[2];
  if (!inputPath) {
    console.log(JSON.stringify({ error: 'missing input file argument' }));
    process.exit(1);
  }

  let idToken;
  try {
    const parsed = JSON.parse(fs.readFileSync(inputPath, 'utf8'));
    idToken = parsed.idToken;
  } catch (e) {
    console.log(JSON.stringify({ error: 'could not read/parse input file' }));
    process.exit(1);
  }

  if (!idToken || typeof idToken !== 'string') {
    console.log(JSON.stringify({ error: 'idToken missing' }));
    process.exit(1);
  }

  try {
    // checkRevoked: true rejects tokens for accounts that were disabled/deleted or had
    // sessions revoked since the token was issued -- important for the "deactivated
    // account can't still send pushes" requirement.
    const decoded = await admin.auth().verifyIdToken(idToken, true);
    console.log(JSON.stringify({ uid: decoded.uid, email: decoded.email || null }));
    process.exit(0);
  } catch (e) {
    console.log(JSON.stringify({ error: e.message || 'verification failed' }));
    process.exit(1);
  }
}

main();
