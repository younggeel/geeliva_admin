const admin = require('firebase-admin');
const serviceAccount = require('./credentials.json');
const projectId = serviceAccount.project_id;

admin.initializeApp({
    credential: admin.credential.cert(serviceAccount),
    storageBucket: `${projectId}.firebasestorage.app`,
});

const bucket = admin.storage().bucket();

const SOURCE_PREFIX = 'images/';
const BACKUP_PREFIX = 'images_old/';
const CONCURRENCY = 5;

// Undo for convertImagesToWebp.js: for every backed-up original in
// images_old/, restore its exact bytes + original content-type back over
// the live path in images/ -- same path, same download token (never
// changed by the conversion), so every existing Firestore URL keeps working
// exactly as it did before conversion.
//
// Usage:
//   node revertImagesFromBackup.js                 -> dry run
//   node revertImagesFromBackup.js --apply          -> actually reverts
//   node revertImagesFromBackup.js --apply --limit=5
const args = process.argv.slice(2);
const APPLY = args.includes('--apply');
const limitArg = args.find((a) => a.startsWith('--limit='));
const LIMIT = limitArg ? parseInt(limitArg.split('=')[1], 10) : Infinity;

async function main() {
    console.log(`Mode: ${APPLY ? 'APPLY (will write changes)' : 'DRY RUN (no writes)'}${LIMIT !== Infinity ? `, limit=${LIMIT}` : ''}`);

    const [backups] = await bucket.getFiles({ prefix: BACKUP_PREFIX });
    console.log(`Found ${backups.length} backup object(s) under ${BACKUP_PREFIX}`);

    const toProcess = backups.filter((f) => !f.name.endsWith('/')).slice(0, LIMIT);

    const results = { reverted: 0, failed: 0 };
    for (let i = 0; i < toProcess.length; i += CONCURRENCY) {
        const batch = toProcess.slice(i, i + CONCURRENCY);
        await Promise.all(batch.map((file) => revertFile(file, results)));
    }

    console.log('\n--- Summary ---');
    console.log(`Reverted: ${results.reverted}`);
    console.log(`Failed:   ${results.failed}`);
}

async function revertFile(backupFile, results) {
    const baseName = backupFile.name.slice(BACKUP_PREFIX.length);
    const liveName = `${SOURCE_PREFIX}${baseName}`;

    try {
        const [backupMeta] = await backupFile.getMetadata();
        const originalContentType = backupMeta.contentType;

        const liveFile = bucket.file(liveName);
        const [liveExists] = await liveFile.exists();
        if (!liveExists) {
            console.log(`SKIP (live file gone, can't revert in place): ${liveName}`);
            return;
        }
        const [liveMeta] = await liveFile.getMetadata();
        const token = liveMeta.metadata && liveMeta.metadata.firebaseStorageDownloadTokens;

        if (!APPLY) {
            console.log(`WOULD REVERT: ${liveName} back to ${originalContentType}`);
            results.reverted++;
            return;
        }

        const [originalBuffer] = await backupFile.download();
        await liveFile.save(originalBuffer, {
            metadata: {
                contentType: originalContentType,
                metadata: { firebaseStorageDownloadTokens: token },
            },
        });

        results.reverted++;
        console.log(`REVERTED: ${liveName} -> ${originalContentType}`);
    } catch (error) {
        results.failed++;
        console.error(`FAILED: ${liveName} - ${error.message}`);
    }
}

main().catch((error) => {
    console.error('Fatal error:', error);
    process.exit(1);
});
