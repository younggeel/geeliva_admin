const admin = require('firebase-admin');
const fs = require('fs');
const path = require('path');
const serviceAccount = require('./credentials.json');

admin.initializeApp({ credential: admin.credential.cert(serviceAccount) });
const db = admin.firestore();

// One-off cleanup: Buea zone's "Supermarket & Groceries" section had accumulated a mix of
// real supermarkets and unrelated retail/fashion/cosmetics/wine-shop vendors. This moves
// everything except the confirmed real supermarkets over to "Shops & Retails", and flips a
// handful of grocery-relevant categories on for the new Supermarket category-tile home screen.
//
// Usage:
//   node migrateSupermarketVendors.js          -> dry run, prints the plan, writes nothing
//   node migrateSupermarketVendors.js --apply   -> actually writes the changes + a backup file

const SUPERMARKET_SECTION_ID = '6319dc53314ee';
const SHOPS_RETAIL_SECTION_ID = '6285dcf511651';
const BUEA_ZONE_ID = 'h5dveqlvo9KtH3o4IoKP';

// Confirmed by title match against the live vendors collection -- these stay under Supermarket.
const KEEP_VENDOR_IDS = new Set([
    'qIMIQV0D5uwt4ZfB7Qj8', // Canada Shop Molyko
    '55nF5z1i4esl0ELUHgNo', // Heart Land supper market
    'KSBxAsqzuU2GOTSkzk5m', // Lily shop Bonduma
    '8gRqJ2W9J7JycMorHJVc', // Afumbom supper market
    'nIw7LhAfSxMcGZtWQRDn', // Y complex Buea
    '1Bp53vV45ip02ohXyE2u', // Emjays supper market
    'lYVQTsRGgRtIBd180Ata', // Amazon Supper Market
    'O0XkI2kvSYfcXcDDTkCi', // TEST COMPLEX
    'ZYGRXyCWTINqZYrBWfFk', // Njeiforbi Complex  bu
]);

// Grocery-relevant categories currently hidden from the section's home-page row -- flipped on
// so the new Supermarket category grid has the full set (SNACK/BAKERY PRODUCT/SOFT DRINKS/
// DAIRY PRODUCT/HOME APPLIANCE are already show_in_homepage:true and need no change).
const CATEGORY_IDS_TO_SHOW = [
    '6a28119d93f33', // alcohol
    '6a28128ebdf0c', // MINERAL WATER
    '6a2a13594532d', // CLEANING AND LAUNDRY
    '6a2d658d436cc', // NON-ALCOHOLIC
    '69c2316325e35', // home& living
];

const args = process.argv.slice(2);
const APPLY = args.includes('--apply');

async function main() {
    console.log(`Mode: ${APPLY ? 'APPLY (will write changes)' : 'DRY RUN (no writes)'}`);

    const backup = { timestamp: new Date().toISOString(), vendors: [], categories: [] };

    // --- Phase A: vendor + product section reassignment ---
    const vendorsSnap = await db.collection('vendors').where('section_id', '==', SUPERMARKET_SECTION_ID).get();
    const toMove = vendorsSnap.docs.filter((doc) => {
        const d = doc.data();
        return d.zoneId === BUEA_ZONE_ID && !KEEP_VENDOR_IDS.has(doc.id);
    });
    const keptInBuea = vendorsSnap.docs.filter((doc) => doc.data().zoneId === BUEA_ZONE_ID && KEEP_VENDOR_IDS.has(doc.id));
    const outsideBuea = vendorsSnap.docs.filter((doc) => doc.data().zoneId !== BUEA_ZONE_ID);

    console.log(`\nSupermarket & Groceries: ${vendorsSnap.size} total vendors`);
    console.log(`  Staying (Buea, on the keep list): ${keptInBuea.length}`);
    console.log(`  Untouched (outside Buea zone): ${outsideBuea.length} -> ${outsideBuea.map((d) => d.data().title).join(', ')}`);
    console.log(`  Moving to Shops & Retails: ${toMove.length}`);
    toMove.forEach((doc) => console.log(`    - ${doc.data().title}  (${doc.id})`));

    if (keptInBuea.length !== KEEP_VENDOR_IDS.size) {
        console.warn(`\nWARNING: expected ${KEEP_VENDOR_IDS.size} keep-list vendors in Buea, found ${keptInBuea.length}. Check KEEP_VENDOR_IDS against current data before applying.`);
    }

    let productCount = 0;
    if (APPLY) {
        for (const vendorDoc of toMove) {
            backup.vendors.push({ vendorId: vendorDoc.id, oldSectionId: SUPERMARKET_SECTION_ID, title: vendorDoc.data().title });
            await vendorDoc.ref.update({ section_id: SHOPS_RETAIL_SECTION_ID });

            const productsSnap = await db.collection('vendor_products').where('vendorID', '==', vendorDoc.id).get();
            const batch = db.batch();
            productsSnap.forEach((productDoc) => batch.update(productDoc.ref, { section_id: SHOPS_RETAIL_SECTION_ID }));
            if (!productsSnap.empty) await batch.commit();
            productCount += productsSnap.size;
        }
        console.log(`\nUpdated ${toMove.length} vendors and ${productCount} product docs.`);
    } else {
        // Dry-run product count estimate, for visibility before --apply.
        for (const vendorDoc of toMove) {
            const productsSnap = await db.collection('vendor_products').where('vendorID', '==', vendorDoc.id).get();
            productCount += productsSnap.size;
        }
        console.log(`\nWould update ${toMove.length} vendors and ${productCount} product docs.`);
    }

    // --- Phase B: category show_in_homepage flips ---
    console.log('\n--- Category show_in_homepage flips ---');
    for (const categoryId of CATEGORY_IDS_TO_SHOW) {
        const doc = await db.collection('vendor_categories').doc(categoryId).get();
        if (!doc.exists) {
            console.warn(`  SKIP: category ${categoryId} not found`);
            continue;
        }
        const d = doc.data();
        if (d.show_in_homepage === true) {
            console.log(`  ${d.title}: already show_in_homepage=true, no change`);
            continue;
        }
        console.log(`  ${d.title}: show_in_homepage false -> true`);
        if (APPLY) {
            backup.categories.push({ categoryId, oldShowInHomepage: d.show_in_homepage === true });
            await doc.ref.update({ show_in_homepage: true });
        }
    }

    if (APPLY) {
        const backupDir = path.join(__dirname, 'migration_backups');
        if (!fs.existsSync(backupDir)) fs.mkdirSync(backupDir);
        const backupPath = path.join(backupDir, `supermarket_migration_${Date.now()}.json`);
        fs.writeFileSync(backupPath, JSON.stringify(backup, null, 2));
        console.log(`\nBackup written to ${backupPath} (use it to manually revert section_id/show_in_homepage if needed).`);
    }
}

main().then(() => process.exit(0)).catch((err) => { console.error(err); process.exit(1); });
