@extends('layouts.app')
@section('content')
<div class="page-wrapper">
    <div class="row page-titles">
        <div class="col-md-5 align-self-center">
            <div class="d-flex top-title-section justify-content-between">
                <div class="d-flex top-title-left align-self-center">
                    <span class="icon mr-3"><img src="{{ asset('images/payment.png') }}"></span>
                    <h3 class="mb-0">Refunds</h3>
                    <span class="counter ml-3 total_count"></span>
                </div>
            </div>
        </div>
        <div class="col-md-7 align-self-center">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('/dashboard') }}">{{ trans('lang.dashboard') }}</a></li>
                <li class="breadcrumb-item active">Refunds</li>
            </ol>
        </div>
    </div>

    <div class="container-fluid">
        <div class="row mb-3">
            <div class="col-md-4">
                <div class="card border"><div class="card-body">
                    <p class="mb-1 text-dark-2">Waiting for a decision</p>
                    <h3 class="mb-0"><span id="pending_count">0</span> &middot; <span id="pending_total">0</span></h3>
                </div></div>
            </div>
            <div class="col-md-4">
                <div class="card border"><div class="card-body">
                    <p class="mb-1 text-dark-2">Refunded to wallets</p>
                    <h3 class="mb-0" id="wallet_total">0</h3>
                </div></div>
            </div>
            <div class="col-md-4">
                <div class="card border"><div class="card-body">
                    <p class="mb-1 text-dark-2">Refunded by Mobile Money</p>
                    <h3 class="mb-0" id="momo_total">0</h3>
                </div></div>
            </div>
        </div>

        <div class="table-list">
            <div class="card border">
                <div class="card-header d-flex justify-content-between align-items-center border-0">
                    <div class="card-header-title">
                        <h3 class="text-dark-2 mb-2 h4">Refund requests</h3>
                        <p class="mb-0 text-dark-2">No refund is paid automatically. Move a pending refund to the customer's wallet, record a refund you sent by Mobile Money, or decline it. The customer is notified each time.</p>
                    </div>
                </div>
                <div class="card-body">
                    <div class="menu-tab mb-3 refund-tabs">
                        <ul>
                            <li class="active"><a href="javascript:void(0)" data-status="pending">Pending</a></li>
                            <li><a href="javascript:void(0)" data-status="refunded">Refunded</a></li>
                            <li><a href="javascript:void(0)" data-status="declined">Declined</a></li>
                            <li><a href="javascript:void(0)" data-status="all">All</a></li>
                        </ul>
                    </div>
                    <div id="refund_error" class="alert alert-danger d-none"></div>
                    <div class="table-responsive m-t-10">
                        <table id="refundTable" class="display nowrap table table-hover table-striped table-bordered" cellspacing="0" width="100%">
                            <thead>
                                <tr>
                                    <th>Requested</th>
                                    <th>Customer</th>
                                    <th>Order</th>
                                    <th>Reason</th>
                                    <th>Paid with</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>{{ trans('lang.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody id="refund_rows"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="refundActionModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="refundActionTitle"></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
                <p id="refundActionSummary" class="mb-3"></p>
                <div class="form-group" id="refundAmountGroup">
                    <label class="control-label">Amount to refund</label>
                    <input type="number" min="0" step="any" class="form-control" id="refundAmount">
                    <small class="form-text text-muted">Lower it for a partial refund.</small>
                </div>
                <div class="form-group" id="refundReferenceGroup">
                    <label class="control-label">Mobile Money transaction reference</label>
                    <input type="text" class="form-control" id="refundReference" maxlength="80">
                </div>
                <div class="form-group">
                    <label class="control-label" id="refundNoteLabel">Note (optional)</label>
                    <textarea class="form-control" id="refundNote" rows="3" maxlength="400"></textarea>
                </div>
                <div id="refundActionError" class="alert alert-danger d-none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary save-form-btn" id="refundActionSubmit">Confirm</button>
                <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ trans('lang.close') }}</button>
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script type="text/javascript">
    var database = firebase.firestore();
    var adminEmail = @json(optional(Auth::user())->email ?? 'admin');
    var notifyUrl = "{{ route('order-status-notification') }}";
    var csrfToken = "{{ csrf_token() }}";
    var orderEditUrl = "{{ url('orders/edit') }}";
    var parcelEditUrl = "{{ url('parcel_orders/edit') }}";
    var currentStatus = 'pending';
    var requestsById = {};
    var currency = { symbol: '', atRight: false, digits: 0 };
    var table = null;

    var REASONS = {
        store_rejected: 'Store rejected the order',
        store_cancelled: 'Store cancelled the order',
        gift_declined: 'Gift declined by the recipient',
        parcel_cancelled: 'Customer cancelled the parcel',
        parcel_expired: 'No driver within 5 hours',
        admin_cancelled: 'Cancelled by an admin'
    };

    function esc(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function money(value) {
        var amount = (parseFloat(value) || 0).toFixed(currency.digits);
        return currency.atRight ? amount + ' ' + currency.symbol : currency.symbol + amount;
    }

    function paymentLabel(method) {
        var m = String(method || '').toLowerCase();
        if (m === 'momo' || m.indexOf('mtn') > -1) return 'MTN Mobile Money';
        if (m.indexOf('orange') > -1) return 'Orange Money';
        if (m === 'wallet') return 'Wallet';
        if (m === 'cod') return 'Cash';
        return method || '-';
    }

    function when(ts) {
        try { var d = ts.toDate(); return d.toDateString() + '<br>' + d.toLocaleTimeString('en-US'); } catch (e) { return ''; }
    }

    function statusBadge(r) {
        if (r.status === 'pending') return '<span class="badge badge-warning">Pending</span>';
        if (r.status === 'declined') return '<span class="badge badge-danger">Declined</span>';
        var how = r.refundMethod === 'mobile_money' ? 'Mobile Money' : 'Wallet';
        return '<span class="badge badge-success">Refunded (' + how + ')</span>';
    }

    function orderLink(r) {
        var base = r.orderCollection === 'parcel_orders' ? parcelEditUrl : orderEditUrl;
        var label = (r.orderCollection === 'parcel_orders' ? 'Parcel ' : 'Order ') + '#' + String(r.orderId || '').substring(0, 8);
        return '<a href="' + base + '/' + encodeURIComponent(r.orderId) + '">' + esc(label) + '</a>' +
            (r.vendorName ? '<br><small>' + esc(r.vendorName) + '</small>' : '');
    }

    function actions(r) {
        if (r.status !== 'pending') {
            var done = [];
            if (r.processedBy) done.push('by ' + esc(r.processedBy));
            if (r.processedAt) done.push(when(r.processedAt).replace('<br>', ' '));
            if (r.mobileMoneyReference) done.push('ref ' + esc(r.mobileMoneyReference));
            if (r.adminNote) done.push(esc(r.adminNote));
            if (r.refundedAmount != null && parseFloat(r.refundedAmount) !== parseFloat(r.amount)) done.push('paid ' + money(r.refundedAmount));
            return '<small>' + done.join('<br>') + '</small>';
        }
        return '<div class="btn-group-vertical btn-group-sm">' +
            '<button class="btn btn-sm btn-success refund-act mb-1" data-act="wallet" data-id="' + esc(r.id) + '">Move to wallet</button>' +
            '<button class="btn btn-sm btn-info refund-act mb-1" data-act="momo" data-id="' + esc(r.id) + '">Mark refunded (Mobile Money)</button>' +
            '<button class="btn btn-sm btn-outline-danger refund-act" data-act="decline" data-id="' + esc(r.id) + '">Decline</button>' +
            '</div>';
    }

    function showError(message) {
        $('#refund_error').text(message).toggleClass('d-none', !message);
    }

    async function loadTotals() {
        // Totals across every request, whatever tab is open.
        var all = await database.collection('refund_requests').get();
        var pendingCount = 0, pendingTotal = 0, walletTotal = 0, momoTotal = 0;
        all.forEach(function (doc) {
            var r = doc.data();
            var amount = parseFloat(r.amount) || 0;
            if (r.status === 'pending') { pendingCount++; pendingTotal += amount; }
            if (r.status === 'refunded') {
                var paid = r.refundedAmount != null ? parseFloat(r.refundedAmount) || 0 : amount;
                if (r.refundMethod === 'mobile_money') momoTotal += paid; else walletTotal += paid;
            }
        });
        $('#pending_count').text(pendingCount);
        $('#pending_total').text(money(pendingTotal));
        $('#wallet_total').text(money(walletTotal));
        $('#momo_total').text(money(momoTotal));
    }

    async function loadRequests() {
        showError('');
        jQuery('#data-table_processing').show();
        try {
            var query = database.collection('refund_requests');
            if (currentStatus !== 'all') query = query.where('status', '==', currentStatus);
            var snapshot = await query.get();
            var rows = snapshot.docs.map(function (doc) { var r = doc.data(); r.id = doc.id; return r; });
            rows.sort(function (a, b) {
                var at = a.createdAt && a.createdAt.toMillis ? a.createdAt.toMillis() : 0;
                var bt = b.createdAt && b.createdAt.toMillis ? b.createdAt.toMillis() : 0;
                return bt - at;
            });
            requestsById = {};
            if (table) { table.destroy(); table = null; }
            var html = rows.map(function (r) {
                requestsById[r.id] = r;
                return '<tr>' +
                    '<td data-order="' + (r.createdAt && r.createdAt.toMillis ? r.createdAt.toMillis() : 0) + '">' + when(r.createdAt) + '</td>' +
                    '<td>' + esc(r.customerName || '-') + (r.customerPhone ? '<br><small>' + esc(r.customerPhone) + '</small>' : '') + '</td>' +
                    '<td>' + orderLink(r) + '</td>' +
                    '<td>' + esc(REASONS[r.reason] || r.reason || '-') + '</td>' +
                    '<td>' + esc(paymentLabel(r.paymentMethod)) + '</td>' +
                    '<td data-order="' + (parseFloat(r.amount) || 0) + '">' + money(r.amount) + '</td>' +
                    '<td>' + statusBadge(r) + '</td>' +
                    '<td>' + actions(r) + '</td>' +
                    '</tr>';
            }).join('');
            $('#refund_rows').html(html);
            $('.total_count').text(rows.length);
            table = $('#refundTable').DataTable({ order: [[0, 'desc']], pageLength: 10, responsive: true, columnDefs: [{ orderable: false, targets: [7] }] });
            await loadTotals();
        } catch (error) {
            console.error('Refunds failed to load', error);
            showError('Refunds could not be loaded: ' + (error.message || error));
        }
        jQuery('#data-table_processing').hide();
    }

    async function notifyCustomer(customerId, subject, message) {
        try {
            var user = await database.collection('users').doc(customerId).get();
            var fcm = user.exists ? (user.data().fcmToken || '') : '';
            if (!fcm) return;
            await $.ajax({ type: 'POST', url: notifyUrl, data: { _token: csrfToken, fcm: fcm, subject: subject, message: message, orderStatus: 'refund' } });
        } catch (e) {
            console.warn('Refund notification failed', e);
        }
    }

    var pendingAction = null;

    function openAction(act, id) {
        var r = requestsById[id];
        if (!r) return;
        pendingAction = { act: act, request: r };
        var titles = { wallet: 'Move refund to the wallet', momo: 'Mark as refunded by Mobile Money', decline: 'Decline this refund' };
        $('#refundActionTitle').text(titles[act]);
        $('#refundActionSummary').html(esc(r.customerName || 'Customer') + ' &middot; ' + money(r.amount) + ' &middot; ' + esc(REASONS[r.reason] || r.reason) + ' &middot; paid with ' + esc(paymentLabel(r.paymentMethod)));
        $('#refundAmount').val(parseFloat(r.amount) || 0);
        $('#refundAmountGroup').toggle(act !== 'decline');
        $('#refundReferenceGroup').toggle(act === 'momo');
        $('#refundReference').val('');
        $('#refundNote').val('');
        $('#refundNoteLabel').text(act === 'decline' ? 'Reason (shown to the customer)' : 'Note (optional)');
        $('#refundActionError').addClass('d-none').text('');
        $('#refundActionModal').modal('show');
    }

    async function moveToWallet(r, amount, note) {
        var reqRef = database.collection('refund_requests').doc(r.id);
        var ledgerRef = database.collection('wallet').doc('refund_' + r.id);
        await database.runTransaction(async function (tx) {
            var req = await tx.get(reqRef);
            if (!req.exists || req.data().status !== 'pending') throw new Error('This refund was already handled.');
            var data = req.data();
            var userRef = database.collection('users').doc(data.customerId);
            var user = await tx.get(userRef);
            if (!user.exists) throw new Error('The customer account no longer exists.');
            var ledger = await tx.get(ledgerRef);
            if (ledger.exists) throw new Error('This refund is already in the wallet.');
            var balance = parseFloat(user.data().wallet_amount) || 0;
            tx.update(userRef, { wallet_amount: parseFloat((balance + amount).toFixed(2)) });
            tx.set(ledgerRef, {
                id: ledgerRef.id, amount: amount, date: firebase.firestore.Timestamp.now(), isTopUp: true,
                order_id: data.orderId, payment_method: 'Wallet', payment_status: 'success', user_id: data.customerId,
                transactionUser: 'customer', note: 'Refund: ' + (REASONS[data.reason] || 'order refund')
            });
            tx.update(reqRef, {
                status: 'refunded', refundMethod: 'wallet', refundedAmount: amount, walletTransactionId: ledgerRef.id,
                adminNote: note, processedBy: adminEmail, processedAt: firebase.firestore.Timestamp.now()
            });
        });
        await notifyCustomer(r.customerId, 'Refund completed',
            money(amount) + ' has been added to your Geeliva wallet for your ' + (r.orderCollection === 'parcel_orders' ? 'parcel' : 'order') + '.');
    }

    async function markMobileMoney(r, amount, reference, note) {
        var reqRef = database.collection('refund_requests').doc(r.id);
        await database.runTransaction(async function (tx) {
            var req = await tx.get(reqRef);
            if (!req.exists || req.data().status !== 'pending') throw new Error('This refund was already handled.');
            tx.update(reqRef, {
                status: 'refunded', refundMethod: 'mobile_money', refundedAmount: amount, mobileMoneyReference: reference,
                adminNote: note, processedBy: adminEmail, processedAt: firebase.firestore.Timestamp.now()
            });
        });
        await notifyCustomer(r.customerId, 'Refund completed',
            money(amount) + ' has been sent back to you by Mobile Money (reference ' + reference + ').');
    }

    async function decline(r, note) {
        var reqRef = database.collection('refund_requests').doc(r.id);
        await database.runTransaction(async function (tx) {
            var req = await tx.get(reqRef);
            if (!req.exists || req.data().status !== 'pending') throw new Error('This refund was already handled.');
            tx.update(reqRef, { status: 'declined', adminNote: note, processedBy: adminEmail, processedAt: firebase.firestore.Timestamp.now() });
        });
        await notifyCustomer(r.customerId, 'Refund update', 'Your refund request was not approved: ' + note);
    }

    $(document).ready(function () {
        database.collection('currencies').where('isActive', '==', true).get().then(function (snapshots) {
            if (!snapshots.empty) {
                var c = snapshots.docs[0].data();
                currency = { symbol: c.symbol || '', atRight: c.symbolAtRight === true, digits: parseInt(c.decimal_degits || 0, 10) || 0 };
            }
            loadRequests();
        }).catch(function () { loadRequests(); });

        $('.refund-tabs a').on('click', function () {
            $('.refund-tabs li').removeClass('active');
            $(this).parent().addClass('active');
            currentStatus = $(this).data('status');
            loadRequests();
        });

        $(document).on('click', '.refund-act', function () {
            openAction($(this).data('act'), String($(this).data('id')));
        });

        $('#refundActionSubmit').on('click', async function () {
            if (!pendingAction) return;
            var r = pendingAction.request;
            var amount = parseFloat($('#refundAmount').val());
            var note = $.trim($('#refundNote').val());
            var reference = $.trim($('#refundReference').val());
            var fail = function (message) { $('#refundActionError').removeClass('d-none').text(message); };
            if (pendingAction.act !== 'decline' && !(amount > 0)) return fail('Enter an amount above 0.');
            if (pendingAction.act !== 'decline' && amount > (parseFloat(r.amount) || 0)) return fail('The amount cannot be more than the requested refund.');
            if (pendingAction.act === 'momo' && !reference) return fail('Enter the Mobile Money transaction reference.');
            if (pendingAction.act === 'decline' && !note) return fail('Give a reason; the customer sees it.');
            var button = $(this).prop('disabled', true);
            try {
                if (pendingAction.act === 'wallet') await moveToWallet(r, amount, note);
                if (pendingAction.act === 'momo') await markMobileMoney(r, amount, reference, note);
                if (pendingAction.act === 'decline') await decline(r, note);
                $('#refundActionModal').modal('hide');
                pendingAction = null;
                await loadRequests();
            } catch (error) {
                console.error('Refund action failed', error);
                fail(error.message || String(error));
            }
            button.prop('disabled', false);
        });
    });
</script>
@endsection
