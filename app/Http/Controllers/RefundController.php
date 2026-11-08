<?php

namespace App\Http\Controllers;

/**
 * Refunds are not paid automatically. Stores (reject/cancel), gift recipients
 * (decline), parcel cancellations and admin cancellations record a pending
 * request in Firestore `refund_requests`; this page lets an admin move it to
 * the customer's wallet, mark it refunded by Mobile Money, or decline it.
 */
class RefundController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index()
    {
        return view('refunds.index');
    }
}
