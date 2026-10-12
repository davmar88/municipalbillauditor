<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\BillController;
use App\Http\Controllers\BillDetailsController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DisputeController;
use App\Http\Controllers\DisputeEventController;
use App\Http\Controllers\FindingController;
use App\Http\Controllers\InboundEmailController;
use App\Http\Controllers\Marketing\PageController;
use App\Http\Controllers\Marketing\SitemapController;
use App\Http\Controllers\PaystackWebhookController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\PropertyController;
use App\Http\Controllers\Staff\ReferenceDataController;
use App\Http\Controllers\Staff\StaffController;
use Illuminate\Support\Facades\Route;

// Public pages, server-rendered for search engines.
Route::get('/', [PageController::class, 'home'])->name('home');
Route::get('municipal-bill-dispute/{metro}', [PageController::class, 'metro'])->name('metro.show');
Route::get('privacy', [PageController::class, 'privacy'])->name('privacy');
Route::get('sitemap.xml', [SitemapController::class, 'sitemap'])->name('sitemap');
Route::get('robots.txt', [SitemapController::class, 'robots'])->name('robots');

// Forwarded e-bills from the email provider's inbound webhook.
Route::post('inbound/email', InboundEmailController::class)->middleware('throttle:120,1')->name('inbound.email');

// Paystack's payment notices.
Route::post('webhooks/paystack', PaystackWebhookController::class)->middleware('throttle:120,1')->name('webhooks.paystack');

Route::middleware(['auth', 'verified', 'organisation'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('bills', [BillController::class, 'index'])->name('bills.index');
    Route::post('bills', [BillController::class, 'store'])->middleware('throttle:30,1')->name('bills.store');
    Route::post('bills/sample', [BillController::class, 'sample'])->middleware('throttle:10,1')->name('bills.sample');
    Route::get('bills/{bill}', [BillController::class, 'show'])->name('bills.show');
    Route::delete('bills/{bill}', [BillController::class, 'destroy'])->name('bills.destroy');
    Route::get('bills/{bill}/file', [BillController::class, 'file'])->name('bills.file');
    Route::get('bills/{bill}/meter-photo', [BillController::class, 'meterPhotoFile'])->name('bills.meter-photo.show');
    Route::post('bills/{bill}/meter-photo', [BillController::class, 'meterPhoto'])->name('bills.meter-photo');
    Route::post('bills/{bill}/recheck', [BillController::class, 'recheck'])->name('bills.recheck');
    Route::patch('bills/{bill}/property', [BillController::class, 'assignProperty'])->name('bills.property');
    Route::get('bills/{bill}/details', [BillDetailsController::class, 'edit'])->name('bills.details.edit');
    Route::put('bills/{bill}/details', [BillDetailsController::class, 'update'])->name('bills.details.update');

    Route::post('findings/{finding}/answer', [FindingController::class, 'answer'])->name('findings.answer');
    Route::post('findings/{finding}/dismiss', [FindingController::class, 'dismiss'])->name('findings.dismiss');
    Route::post('findings/{finding}/restore', [FindingController::class, 'restore'])->name('findings.restore');

    Route::get('bills/{bill}/dispute', [DisputeController::class, 'create'])->name('disputes.create');
    Route::post('bills/{bill}/dispute', [DisputeController::class, 'store'])->name('disputes.store');
    Route::get('disputes', [DisputeController::class, 'index'])->name('disputes.index');
    Route::get('disputes/{dispute}', [DisputeController::class, 'show'])->name('disputes.show');
    Route::post('disputes/{dispute}/events', [DisputeEventController::class, 'store'])->name('disputes.events.store');

    Route::get('portfolio', PortfolioController::class)->name('portfolio');
    Route::get('properties', [PropertyController::class, 'index'])->name('properties.index');
    Route::post('properties', [PropertyController::class, 'store'])->name('properties.store');
    Route::put('properties/{property}', [PropertyController::class, 'update'])->name('properties.update');
    Route::patch('properties/{property}/municipality', [PropertyController::class, 'setMunicipality'])->name('properties.municipality');
    Route::delete('properties/{property}', [PropertyController::class, 'destroy'])->name('properties.destroy');

    Route::get('credit', [CreditController::class, 'index'])->name('credit.index');
    Route::post('credit/top-up', [CreditController::class, 'topUp'])->middleware('throttle:20,1')->name('credit.top-up');
    Route::post('credit/invoices/{invoice}/pay', [CreditController::class, 'payInvoice'])->middleware('throttle:20,1')->name('credit.invoices.pay');
    Route::get('credit/callback', [CreditController::class, 'callback'])->name('credit.callback');

    Route::get('settings/account', [AccountController::class, 'edit'])->name('account.edit');
    Route::delete('settings/account/card', [AccountController::class, 'destroyCard'])->name('account.card.destroy');
    Route::patch('settings/account/disputes', [AccountController::class, 'updateDisputes'])->name('account.disputes.update');
    Route::patch('settings/account', [AccountController::class, 'update'])->name('account.update');
    Route::delete('settings/account/bills', [AccountController::class, 'destroyBills'])->name('account.bills.destroy');
});

Route::middleware(['auth', 'verified', 'can:staff'])->prefix('staff')->name('staff.')->group(function () {
    Route::get('/', [StaffController::class, 'index'])->name('index');
    Route::post('disputes/{dispute}/lodge', [StaffController::class, 'lodge'])->name('disputes.lodge');
    Route::post('disputes/{dispute}/send', [StaffController::class, 'send'])->name('disputes.send');
    Route::post('replies/{message}', [StaffController::class, 'recordReply'])->name('replies.record');
    Route::post('disputes/{dispute}/events', [StaffController::class, 'record'])->name('disputes.events.store');
    Route::get('bills/{bill}/file', [StaffController::class, 'billFile'])->name('bills.file');
    Route::post('organisations/{organisation}/eft', [StaffController::class, 'recordEft'])->name('organisations.eft');
    Route::get('margin', [StaffController::class, 'margin'])->name('margin');
    Route::get('reference', [ReferenceDataController::class, 'index'])->name('reference');
    Route::put('municipalities/{municipality:id}', [ReferenceDataController::class, 'updateMunicipality'])->name('municipalities.update');
    Route::post('outages', [ReferenceDataController::class, 'storeOutage'])->name('outages.store');
    Route::delete('outages/{outage}', [ReferenceDataController::class, 'destroyOutage'])->name('outages.destroy');
    Route::post('tariffs', [ReferenceDataController::class, 'storeTariff'])->name('tariffs.store');
    Route::delete('tariffs/{tariffRate}', [ReferenceDataController::class, 'destroyTariff'])->name('tariffs.destroy');
});

require __DIR__.'/settings.php';
