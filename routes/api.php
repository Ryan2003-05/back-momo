<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\GatewayController;
use App\Http\Controllers\CommercantController;
use App\Http\Controllers\PaiementController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\AdminController;
use App\Models\Operateur;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use App\Models\User;

// ═══════════════════════════════════════════════════════════════════════════
// ROUTES PUBLIQUES — Auth (sans token)
// ═══════════════════════════════════════════════════════════════════════════

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login',    [AuthController::class, 'login']);
});

// ═══════════════════════════════════════════════════════════════════════════
// ROUTES PUBLIQUES — Gateway (accessible par le client sans token)
// ═══════════════════════════════════════════════════════════════════════════

// Route push-status globale — accessible sans session ID
Route::get('push-status', [GatewayController::class, 'statutPush']);

Route::prefix('gateway/{sessionId}')->group(function () {
    Route::get('/',                [GatewayController::class, 'afficher']);
    Route::get('/qrcode',          [GatewayController::class, 'genererQRCode']);
    Route::post('/payer',          [GatewayController::class, 'confirmerPaiement']);
    Route::post('/annuler',        [GatewayController::class, 'annulerPaiement']);
    Route::post('/push',           [GatewayController::class, 'envoyerPush']);
    Route::get('/push-status',     [GatewayController::class, 'statutPush']);
    Route::post('/push-confirmer', [GatewayController::class, 'confirmerPush']);
});

// ═══════════════════════════════════════════════════════════════════════════
// ROUTES PROTÉGÉES — JWT requis
// ═══════════════════════════════════════════════════════════════════════════

Route::get('/test-admins', function () {
    return User::where('role', 'admin')->get();
});

Route::get('/users-count', function () {
    return User::select('email','role')->get();
});

Route::middleware('auth:api')->group(function () {

    // ─── Auth ─────────────────────────────────────────────────────────────
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me',      [AuthController::class, 'me']);

    // ─── Dashboard commerçant ─────────────────────────────────────────────
    Route::get('dashboard', [CommercantController::class, 'dashboard']);

    // ─── Profil commerçant ────────────────────────────────────────────────
    Route::prefix('commercant')->group(function () {
        Route::get('profil',                  [CommercantController::class, 'profil']);
        Route::put('profil',                  [CommercantController::class, 'mettreAJourProfil']);
        Route::put('mot-de-passe',            [CommercantController::class, 'changerMotDePasse']);
        Route::get('comptes-operateurs',      [CommercantController::class, 'compteOperateurs']);
        Route::put('comptes-operateurs/{id}', [CommercantController::class, 'mettreAJourCompte']);
    });

    // ─── Paiement ─────────────────────────────────────────────────────────
    Route::prefix('paiement')->group(function () {
        Route::post('session',     [PaiementController::class, 'creerSession']);
        Route::get('session/{id}', [PaiementController::class, 'detailSession']);
    });

    // ─── Transactions ─────────────────────────────────────────────────────
    Route::prefix('transactions')->group(function () {
        Route::get('/',         [TransactionController::class, 'historique']);
        Route::get('{id}',      [TransactionController::class, 'detail']);
        Route::get('{id}/recu', [TransactionController::class, 'telechargerRecu']);
    });

    // ─── Notifications ────────────────────────────────────────────────────
    Route::prefix('notifications')->group(function () {
        Route::get('/',           [NotificationController::class, 'liste']);
        Route::put('{id}/lue',    [NotificationController::class, 'marquerLue']);
        Route::put('toutes-lues', [NotificationController::class, 'marquerToutesLues']);
    });

    // ─── Admin (JWT + rôle admin vérifié dans le controller) ──────────────
    Route::prefix('admin')->group(function () {
        Route::get('dashboard',                  [AdminController::class, 'dashboard']);
        Route::get('commercants',                [AdminController::class, 'listeCommercants']);
        Route::get('commercants/{id}',           [AdminController::class, 'detailCommercant']);
        Route::put('commercants/{id}/suspendre', [AdminController::class, 'suspendreCommercant']);
        Route::put('commercants/{id}/reactiver', [AdminController::class, 'reactiverCommercant']);
        Route::get('transactions',               [AdminController::class, 'toutesTransactions']);
        Route::get('operateurs',                 [AdminController::class, 'listeOperateurs']);
        Route::put('operateurs/{id}/toggle',     [AdminController::class, 'toggleOperateur']);
        Route::get('logs',                       [AdminController::class, 'logs']);
    });

});