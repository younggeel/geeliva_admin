// Mints a Firebase Auth custom token for a given uid, so a client that has already
// proven its identity through some non-Firebase-SDK flow (WhatsApp OTP verification,
// TOTP MFA REST challenge) can finish signing in via FirebaseAuth.signInWithCustomToken,
// which the Flutter SDK does support even where the specific challenge flow isn't.
//
// Usage: node mintCustomToken.js <path-to-json-file-containing-{"uid":"..."}>
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

  let uid;
  try {
    const parsed = JSON.parse(fs.readFileSync(inputPath, 'utf8'));
    uid = parsed.uid;
  } catch (e) {
    console.log(JSON.stringify({ error: 'could not read/parse input file' }));
    process.exit(1);
  }

  if (!uid || typeof uid !== 'string') {
    console.log(JSON.stringify({ error: 'uid missing' }));
    process.exit(1);
  }

  try {
    const token = await admin.auth().createCustomToken(uid);
    console.log(JSON.stringify({ token }));
    process.exit(0);
  } catch (e) {
    console.log(JSON.stringify({ error: e.message || 'mint failed' }));
    process.exit(1);
  }
}

main();
