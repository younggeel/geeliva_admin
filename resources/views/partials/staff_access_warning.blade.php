{{-- Shown wherever admin accounts or roles are managed. Remove once the
     Firebase admin token carries the panel role and the Firestore rules
     check it (see FirebaseController::adminToken). --}}
<div class="alert alert-warning" role="alert" style="border-left: 4px solid #f0ad4e;">
    <strong>Before adding staff accounts:</strong>
    every account in this admin panel currently gets <u>full access to the whole database</u>,
    whatever role you give it here. Role permissions only hide menu items.
    Restrict database access per role first (per-role Firebase claims + matching Firestore rules),
    then add staff.
</div>
