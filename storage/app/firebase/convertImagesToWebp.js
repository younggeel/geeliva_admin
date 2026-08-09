const admin = require('firebase-admin');
const sharp = require('sharp');
const serviceAccount = require('./credentials.json');
const projectId = serviceAccount.project_id;

admin.initializeApp({
    credential: admin.credential.cert(serviceAccount),
    storageBucket: `${projectId}.firebasestorage.app`,
});

const bucket = admin.storage().bucket();

// 'digitalProducts/' is deliberately excluded -- those are purchased downloadable files
// (not necessarily images), and converting/resizing them would corrupt what a customer paid
// for. 'images/' and 'profileImage/' cover vendor store photos and item/product photos --
// the actual "vendor/product images" this sweep is meant for (confirmed via the Flutter
// vendor app's upload code: add_product_controller.dart uploads product photos to
// 'profileImage/{uid}/{filename}' despite the folder's name).
const SOURCE_PREFIXES = ['images/', 'profileImage/'];
const CONVERTIBLE_CONTENT_TYPES = new Set(['image/jpeg', 'image/jpg', 'image/png']);
const CONCURRENCY = 5;
const WEBP_QUALITY = 80;
// Bounding box the image is scaled to FIT INSIDE (aspect ratio preserved, never cropped) --
// "scaled so the item is visible" means shrink-to-fit, not crop-to-fill.
const MAX_DIMENSION = 1024;

// Usage:
//   node convertImagesToWebp.js                 -> dry run, all files, all prefixes
//   node convertImagesToWebp.js --apply          -> actually converts, all files
//   node convertImagesToWebp.js --apply --limit=5 -> actually converts, first 5 eligible files only
const args = process.argv.slice(2);
const APPLY = args.includes('--apply');
const limitArg = args.find((a) => a.startsWith('--limit='));
const LIMIT = limitArg ? parseInt(limitArg.split('=')[1], 10) : Infinity;

function backupPrefixFor(sourcePrefix) {
    return sourcePrefix.replace(/\/$/, '') + '_old/';
}

async function main() {
    console.log(`Mode: ${APPLY ? 'APPLY (will write changes)' : 'DRY RUN (no writes)'}${LIMIT !== Infinity ? `, limit=${LIMIT}` : ''}`);
    console.log(`Prefixes: ${SOURCE_PREFIXES.join(', ')}`);

    const results = { converted: 0, skipped: 0, failed: 0, bytesBefore: 0, bytesAfter: 0 };
    let remainingLimit = LIMIT;

    for (const sourcePrefix of SOURCE_PREFIXES) {
        if (remainingLimit <= 0) break;

        const [files] = await bucket.getFiles({ prefix: sourcePrefix });
        console.log(`Found ${files.length} object(s) under ${sourcePrefix}`);

        const eligible = [];
        for (const file of files) {
            if (file.name.endsWith('/')) continue; // folder placeholder object
            if (file.name.toLowerCase().endsWith('.webp')) {
                console.log(`SKIP (already webp): ${file.name}`);
                continue;
            }
            eligible.push(file);
        }

        const toProcess = eligible.slice(0, remainingLimit);
        remainingLimit -= toProcess.length;

        for (let i = 0; i < toProcess.length; i += CONCURRENCY) {
            const batch = toProcess.slice(i, i + CONCURRENCY);
            await Promise.all(batch.map((file) => processFile(file, sourcePrefix, results)));
        }
    }

    console.log('\n--- Summary ---');
    console.log(`Converted: ${results.converted}`);
    console.log(`Skipped:   ${results.skipped}`);
    console.log(`Failed:    ${results.failed}`);
    if (results.converted > 0 && APPLY) {
        const savedKb = ((results.bytesBefore - results.bytesAfter) / 1024).toFixed(1);
        const pct = (((results.bytesBefore - results.bytesAfter) / results.bytesBefore) * 100).toFixed(1);
        console.log(`Size: ${(results.bytesBefore / 1024).toFixed(1)} KB -> ${(results.bytesAfter / 1024).toFixed(1)} KB (saved ${savedKb} KB, ${pct}%)`);
    } else if (results.converted > 0) {
        console.log(`Would-convert total size: ${(results.bytesBefore / 1024).toFixed(1)} KB (post-conversion size only known after --apply)`);
    }
}

async function processFile(file, sourcePrefix, results) {
    const baseName = file.name.slice(sourcePrefix.length);
    const backupPath = `${backupPrefixFor(sourcePrefix)}${baseName}`;

    try {
        const [metadata] = await file.getMetadata();
        const contentType = metadata.contentType || '';

        if (!CONVERTIBLE_CONTENT_TYPES.has(contentType)) {
            console.log(`SKIP (not jpg/png, contentType=${contentType}): ${file.name}`);
            results.skipped++;
            return;
        }

        // NOTE: a backup existing does NOT mean conversion finished -- a prior
        // run can fail after the backup upload but before the webp overwrite
        // (network blip mid-batch), which would leave the live file still
        // jpg/png with a backup already sitting there. The contentType check
        // above is what actually gates "needs conversion"; backup existence
        // only controls whether we skip re-uploading the backup below.
        const [alreadyBackedUp] = await bucket.file(backupPath).exists();

        const token = metadata.metadata && metadata.metadata.firebaseStorageDownloadTokens;
        if (!token) {
            console.log(`SKIP (no download token found, unsafe to convert in place): ${file.name}`);
            results.skipped++;
            return;
        }

        if (!APPLY) {
            console.log(`WOULD CONVERT: ${file.name} (${contentType}, ${(Number(metadata.size) / 1024).toFixed(1)} KB) -> backup to ${backupPath}, resize-inside ${MAX_DIMENSION}px`);
            results.converted++;
            results.bytesBefore += Number(metadata.size);
            return;
        }

        const [originalBuffer] = await file.download();

        // Backup original bytes, untouched, before doing anything destructive
        // -- unless a prior interrupted run already got this far.
        if (!alreadyBackedUp) {
            await bucket.file(backupPath).save(originalBuffer, {
                metadata: { contentType },
            });
        }

        // fit:'inside' scales down to fit within MAX_DIMENSION x MAX_DIMENSION while
        // preserving aspect ratio -- never crops, so the item stays fully visible.
        // withoutEnlargement avoids upscaling images already smaller than the box.
        const webpBuffer = await sharp(originalBuffer)
            .resize({ width: MAX_DIMENSION, height: MAX_DIMENSION, fit: 'inside', withoutEnlargement: true })
            .webp({ quality: WEBP_QUALITY })
            .toBuffer();

        // Overwrite the same path with the converted bytes, re-applying the
        // SAME download token -- this is what keeps every existing Firestore
        // URL pointing at this path working unchanged.
        await file.save(webpBuffer, {
            metadata: {
                contentType: 'image/webp',
                metadata: { firebaseStorageDownloadTokens: token },
            },
        });

        results.converted++;
        results.bytesBefore += originalBuffer.length;
        results.bytesAfter += webpBuffer.length;
        console.log(`CONVERTED: ${file.name} (${(originalBuffer.length / 1024).toFixed(1)} KB -> ${(webpBuffer.length / 1024).toFixed(1)} KB)`);
    } catch (error) {
        results.failed++;
        console.error(`FAILED: ${file.name} - ${error.message}`);
    }
}

main().catch((error) => {
    console.error('Fatal error:', error);
    process.exit(1);
});
