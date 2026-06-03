<?php

namespace App\Http\Controllers;

use App\Models\SessionPaiement;
use App\Models\Transaction;
use App\Models\Recu;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class GatewayController extends Controller
{
    //  Préfixes opérateurs Bénin (RG16)

    private array $prefixesMTN    = [42,46,50,51,52,53,54,56,57,59,61,62,66,67,69,90,91,96,97];
    private array $prefixesMoov   = [55,58,60,63,64,65,68,94,95,98];
    private array $prefixesCeltiis = [40,41,43,44,47];

    //  Afficher les infos de la session 

    public function afficher(string $sessionId)
    {
        $session = SessionPaiement::with([
            'compteOperateur.operateur',
            'commercant',
        ])->find($sessionId);

        if (!$session) {
            return response()->json([
                'message' => 'Session introuvable.',
            ], 404);
        }

        // Vérifier expiration
        if ($session->estExpiree()) {
            $session->update(['statut' => 'EXPIREE']);

            // Notifier le commerçant que la session a expiré sans paiement
            $this->notifierCommerçant($session, null, 'EXPIREE');

            return response()->json([
                'message' => 'Cette session de paiement a expiré.',
                'statut'  => 'EXPIREE',
            ], 410);
        }

        // Vérifier si déjà payée
        if ($session->statut === 'PAYEE') {
            return response()->json([
                'message' => 'Ce paiement a déjà été effectué.',
                'statut'  => 'PAYEE',
            ], 409);
        }

        // Vérifier si annulée
        if ($session->statut === 'ANNULEE') {
            return response()->json([
                'message' => 'Ce paiement a été annulé.',
                'statut'  => 'ANNULEE',
            ], 409);
        }

        $operateur  = $session->compteOperateur->operateur;
        $commercant = $session->commercant;

        // Récupérer tous les opérateurs actifs du commerçant
        $operateursAcceptes = $commercant->compteOperateurs()
            ->where('actif', true)
            ->with('operateur')
            ->get()
            ->map(fn($co) => $co->operateur->nom)
            ->toArray();

        return response()->json([
            'session_id'          => $session->id,
            'montant'             => $session->montant,
            'libelle'             => $session->libelle,
            'nom_commerce'        => $commercant->nom_entreprise,
            'type_paiement'       => $session->type_paiement,
            'expires_at'          => $session->expires_at,
            'secondes_restantes'  => max(0, now()->diffInSeconds($session->expires_at, false)),
            'operateurs_acceptes' => $operateursAcceptes,
        ], 200);
    }

    // Générer le QR code image 

    public function genererQRCode(Request $request, string $sessionId)
    {
        $session = SessionPaiement::find($sessionId);

        if (!$session) {
            return response()->json(['message' => 'Session introuvable.'], 404);
        }

        if ($session->estExpiree()) {
            return response()->json(['message' => 'Session expirée.'], 410);
        }

        // URL de la page gateway — c'est cette URL que le client verra en scannant
        $url = $this->urlGatewayClient($sessionId, $request->headers->get('origin'));

        $qrCode = QrCode::format('svg')
                        ->size(300)
                        ->errorCorrection('H')
                        ->generate($url);

        return response($qrCode, 200)
               ->header('Content-Type', 'image/svg+xml')
               ->header('Access-Control-Allow-Origin', '*');
    }

    //  Confirmer le paiement 

    public function confirmerPaiement(Request $request, string $sessionId)
    {
        $request->validate([
            'numero_client' => 'required|string|max:20',
            'code_momo'     => 'required|string|max:10',
            'forcer_echec'  => 'nullable|boolean',
        ]);

        $session = SessionPaiement::with([
            'compteOperateur.operateur',
            'commercant',
        ])->find($sessionId);

        if (!$session) {
            return response()->json(['message' => 'Session introuvable.'], 404);
        }

        // Vérifier expiration
        if ($session->estExpiree()) {
            $session->update(['statut' => 'EXPIREE']);
            $this->notifierCommerçant($session, null, 'EXPIREE');

            return response()->json([
                'message' => 'Session expirée. Demandez un nouveau paiement au commerçant.',
                'statut'  => 'EXPIREE',
            ], 410);
        }

        // Vérifier déjà payée (RG9)
        if ($session->transaction) {
            return response()->json([
                'message' => 'Ce paiement a déjà été effectué.',
            ], 409);
        }

        // Vérifier annulée
        if ($session->statut === 'ANNULEE') {
            return response()->json([
                'message' => 'Cette session a été annulée.',
            ], 409);
        }

        $compteOperateur = $session->compteOperateur;
        $operateur       = $compteOperateur->operateur;

        // RG17 : vérifier que l'opérateur est actif
        if (!$operateur->actif) {
            return response()->json([
                'message' => 'Opérateur ' . $operateur->nom . ' indisponible.',
            ], 503);
        }

        // RG16 : détecter l'opérateur depuis le numéro client
        $operateurDetecte = $this->detecterOperateur($request->numero_client);

        if ($operateurDetecte === 'Inconnu') {
            return response()->json([
                'message' => 'Numéro non reconnu. Vérifiez votre numéro mobile money.',
            ], 422);
        }

        // Vérifier que l'opérateur du client est accepté par le commerçant
        $operateursAcceptes = $session->commercant->compteOperateurs()
            ->where('actif', true)
            ->with('operateur')
            ->get()
            ->map(fn($co) => $co->operateur->nom)
            ->toArray();

        if (!in_array($operateurDetecte, $operateursAcceptes)) {
            return response()->json([
                'message'             => 'Votre opérateur ' . $operateurDetecte . ' n\'est pas accepté par ce commerçant.',
                'operateurs_acceptes' => $operateursAcceptes,
            ], 422);
        }

        // Simulation Gateway 

        if ($request->has('forcer_echec')) {
            $succes = !$request->boolean('forcer_echec');
        } else {
            $succes = rand(1, 100) <= 85;
        }

        $statut = $succes ? 'SUCCESS' : 'FAILED';

        // RG10 : créer la transaction automatiquement
        $transaction = Transaction::create([
            'session_paiement_id' => $session->id,
            'operateur_id'        => $operateur->id,
            'reference_gateway'   => 'TXN-' . strtoupper(Str::random(12)),
            'statut'              => $statut,
            'numero_client'       => $request->numero_client,
            'created_at'          => now(),
        ]);

        // Mettre à jour le statut de la session
        $session->update(['statut' => $succes ? 'PAYEE' : 'EN_ATTENTE']);

        // RG14 : si succès → reçu + solde mis à jour
        if ($succes) {
            Recu::create([
                'transaction_id' => $transaction->id,
                'reference'      => 'REC-' . strtoupper(Str::random(8)),
                'montant'        => $session->montant,
                'date_emission'  => now(),
            ]);

            $compteOperateur->increment('solde', $session->montant);
        }

        // RG14 : notification commerçant
        $this->notifierCommerçant($session, $transaction, $statut);

        return response()->json([
            'message'      => $succes
                ? 'Paiement confirmé avec succès !'
                : 'Paiement échoué. Solde insuffisant ou erreur réseau.',
            'statut'       => $statut,
            'reference'    => $transaction->reference_gateway,
            'montant'      => $session->montant,
            'operateur'    => $operateur->nom,
            'nom_commerce' => $session->commercant->nom_entreprise,
            'notification_message' => $this->messageNotificationPaiement($session, $operateur->nom, $statut, $transaction->numero_client),
            'redirect_to'  => '/nouveau-paiement',
        ], 200);
    }

    //  Annuler le paiement 

    public function annulerPaiement(string $sessionId)
    {
        $session = SessionPaiement::with([
            'compteOperateur.operateur',
            'commercant',
        ])->find($sessionId);

        if (!$session) {
            return response()->json(['message' => 'Session introuvable.'], 404);
        }

        if ($session->statut !== 'EN_ATTENTE') {
            return response()->json([
                'message' => 'Cette session ne peut pas être annulée.',
            ], 409);
        }

        // Marquer la session comme annulée
        $session->update(['statut' => 'ANNULEE']);

        // Créer une transaction FAILED
        $transaction = Transaction::create([
            'session_paiement_id' => $session->id,
            'operateur_id'        => $session->compteOperateur->operateur->id,
            'reference_gateway'   => 'ANNULE-' . strtoupper(Str::random(8)),
            'statut'              => 'FAILED',
            'numero_client'       => 'ANNULE_PAR_CLIENT',
            'created_at'          => now(),
        ]);

        // Notifier le commerçant
        $this->notifierCommerçant($session, $transaction, 'ANNULEE');

        return response()->json([
            'message' => 'Paiement annulé. Le commerçant a été notifié.',
            'statut'  => 'ANNULEE',
            'redirect_to' => '/nouveau-paiement',
        ], 200);
    }

    //  RG16 : Détecter l'opérateur depuis le préfixe 

    private function detecterOperateur(string $numero): string
    {
        // Nettoyer le numéro
        $numero  = preg_replace('/[\s\+\-]/', '', $numero);
        $numero  = preg_replace('/^(229|01229|01)/', '', $numero);
        $prefixe = (int) substr($numero, 0, 2);

        return match(true) {
            in_array($prefixe, $this->prefixesMTN)    => 'MTN',
            in_array($prefixe, $this->prefixesMoov)   => 'Moov',
            in_array($prefixe, $this->prefixesCeltiis) => 'Celtiis',
            default                                   => 'Inconnu',
        };
    }

    // Notifier le commerçant 

    private function notifierCommerçant(
        SessionPaiement $session,
        ?Transaction $transaction,
        string $statut
    ): void {
        if (!$transaction) return;

        Notification::create([
            'commercant_id'  => $session->commercant_id,
            'transaction_id' => $transaction->id,
            'message'        => $this->messageNotificationPaiement($session, $transaction->operateur->nom, $statut, $transaction->numero_client),
            'lue'            => false,
            'created_at'     => now(),
        ]);
    }

    private function messageNotificationPaiement(SessionPaiement $session, string $operateurNom, string $statut = 'SUCCESS', string $numeroClient = 'N/A'): string
    {
        if ($statut === 'ANNULEE') {
            return 'Le paiement de '
                . number_format((float) $session->montant, 0, ',', ' ')
                . ' FCFA a ete annule. Numero: '
                . $numeroClient
                . '. Operateur: '
                . $operateurNom
                . '.';
        }

        return 'Vous venez de recevoir un paiement de '
            . number_format((float) $session->montant, 0, ',', ' ')
            . ' FCFA. Numero: '
            . $numeroClient
            . '. Operateur: '
            . $operateurNom
            . '.';
    }

    //  Envoyer un Push USSD au client (simulation Africa's Talking) 

    public function envoyerPushUSSD(Request $request, string $sessionId)
    {
        $request->validate([
            'numero_client' => 'required|string|max:20',
        ]);

        $session = SessionPaiement::with([
            'compteOperateur.operateur',
            'commercant',
        ])->find($sessionId);

        if (!$session) {
            return response()->json(['message' => 'Session introuvable.'], 404);
        }

        // Vérifier expiration
        if ($session->estExpiree()) {
            $session->update(['statut' => 'EXPIREE']);

            return response()->json([
                'message' => 'Session expirée.',
                'statut'  => 'EXPIREE',
            ], 410);
        }

        // Vérifier que la session est encore en attente
        if ($session->statut !== 'EN_ATTENTE') {
            return response()->json([
                'message' => 'Cette session n\'est plus disponible.',
            ], 409);
        }

        // RG16 : détecter l'opérateur depuis le numéro client
        $operateurDetecte = $this->detecterOperateur($request->numero_client);

        if ($operateurDetecte === 'Inconnu') {
            return response()->json([
                'message' => 'Numéro non reconnu. Vérifiez le numéro mobile money du client.',
            ], 422);
        }

        // Vérifier que l'opérateur du client est accepté par le commerçant
        $operateursAcceptes = $session->commercant->compteOperateurs()
            ->where('actif', true)
            ->with('operateur')
            ->get()
            ->map(fn($co) => $co->operateur->nom)
            ->toArray();

        if (!in_array($operateurDetecte, $operateursAcceptes)) {
            return response()->json([
                'message'             => 'L\'opérateur ' . $operateurDetecte . ' n\'est pas accepté par ce commerçant.',
                'operateurs_acceptes' => $operateursAcceptes,
            ], 422);
        }

        // Lien de la page gateway
        $lienGateway = $this->urlGatewayClient($sessionId);

        //  Simulation Africa's Talking

        $contenuSMS = 'PayPME - Paiement de ' . number_format($session->montant, 0, ',', ' ')
                    . ' FCFA demandé par ' . $session->commercant->nom_entreprise
                    . '. Cliquez pour payer : ' . $lienGateway
                    . ' (Expire dans 3 min)';

        return response()->json([
            'message'            => 'Push USSD envoyé avec succès au client.',
            'numero_client'      => $request->numero_client,
            'operateur_detecte'  => $operateurDetecte,
            'lien_gateway'       => $lienGateway,
            'contenu_sms'        => $contenuSMS,
            'statut_envoi'       => 'ENVOYE',
            'expires_at'         => $session->expires_at,
        ], 200);
    }

    // Envoyer un push USSD simulé 
    public function envoyerPush(Request $request, string $sessionId)
    {
        $request->validate([
            'numero_client' => 'required|string|max:20',
        ]);

        $session = SessionPaiement::find($sessionId);

        if (!$session) {
            return response()->json(['message' => 'Session introuvable.'], 404);
        }

        if ($session->estExpiree()) {
            return response()->json([
                'message' => 'Session expirée.',
                'statut' => 'EXPIREE'
            ], 410);
        }

        // Supprimer les anciens push de cette session
        \App\Models\PushRequest::where('session_paiement_id', $sessionId)->delete();

        // Créer le push — expire dans 3 minutes
        $push = \App\Models\PushRequest::create([
            'session_paiement_id' => $sessionId,
            'numero_client'       => $request->numero_client,
            'statut'              => 'EN_ATTENTE',
            'expires_at'          => now()->addMinutes(3),
        ]);

        $commercant = $session->commercant;
        $operateur  = $session->compteOperateur->operateur;

        return response()->json([
            'message'    => 'Push envoyé au client ' . $request->numero_client,
            'push_id'    => $push->id,
            'push_url'   => $this->urlPushClient($request->numero_client),
            'montant'    => $session->montant,
            'libelle'    => $session->libelle,
            'operateur'  => $operateur->nom,
            'commerce'   => $commercant->nom_entreprise,
            'expires_at' => $push->expires_at,
        ], 201);
    }

    // Vérifier le statut du push (polling client)
    public function statutPush(Request $request)
    {
        $query = \App\Models\PushRequest::where('statut', 'EN_ATTENTE');

        if ($request->filled('numero')) {
            $query->where('numero_client', $request->numero);
        }

        $push = $query->latest()->first();

        if (!$push) {
            return response()->json(['push' => null], 200);
        }

        if ($push->estExpire()) {
            $push->update(['statut' => 'EXPIRE']);

            return response()->json([
                'push' => null,
                'statut_push' => 'EXPIRE'
            ], 200);
        }

        $session    = $push->sessionPaiement;
        $commercant = $session->commercant;
        $operateur  = $session->compteOperateur->operateur;

        return response()->json([
            'push' => [
                'id'         => $push->id,
                'session_id' => $session->id,
                'montant'    => $session->montant,
                'libelle'    => $session->libelle,
                'operateur'  => $operateur->nom,
                'commerce'   => $commercant->nom_entreprise,
                'numero'     => $push->numero_client,
                'expires_at' => $push->expires_at,
            ],
        ], 200);
    }

    // Confirmer ou refuser le push (côté client)
    private function frontendUrl(?string $origin = null): string
    {
        if ($origin) {
            return rtrim($origin, '/');
        }

        return rtrim(config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173')), '/');
    }

    private function urlGatewayClient(string $sessionId, ?string $origin = null): string
    {
        return $this->frontendUrl($origin) . '/gateway/' . $sessionId;
    }

    private function urlPushClient(?string $numeroClient = null): string
    {
        $url = $this->frontendUrl() . '/push-client';

        if ($numeroClient) {
            $url .= '?numero=' . urlencode($numeroClient);
        }

        return $url;
    }

    public function confirmerPush(Request $request, string $sessionId)
    {
        $request->validate([
            'push_id' => 'required|string',
            'pin'     => 'required|string|min:4',
            'action'  => 'required|in:confirmer,refuser',
        ]);

        $push = \App\Models\PushRequest::find($request->push_id);

        if (!$push || $push->session_paiement_id !== $sessionId) {
            return response()->json([
                'message' => 'Demande push introuvable.'
            ], 404);
        }

        if ($push->statut !== 'EN_ATTENTE') {
            return response()->json([
                'message' => 'Cette demande a déjà été traitée.'
            ], 409);
        }

        if ($push->estExpire()) {
            $push->update(['statut' => 'EXPIRE']);

            return response()->json([
                'message' => 'Demande expirée.'
            ], 410);
        }

        if ($request->action === 'refuser') {
            $push->update(['statut' => 'REFUSE']);

            $session = SessionPaiement::with(['compteOperateur.operateur', 'transaction'])
                ->find($sessionId);

            if ($session && $session->statut === 'EN_ATTENTE' && !$session->transaction) {
                $session->update(['statut' => 'ANNULEE']);

                $transaction = Transaction::create([
                    'session_paiement_id' => $session->id,
                    'operateur_id'        => $session->compteOperateur->operateur->id,
                    'reference_gateway'   => 'REFUS-' . strtoupper(Str::random(8)),
                    'statut'              => 'FAILED',
                    'numero_client'       => $push->numero_client,
                    'created_at'          => now(),
                ]);

                $this->notifierCommerçant($session, $transaction, 'ANNULEE');
            }

            return response()->json([
                'message' => 'Paiement refusé.',
                'statut' => 'ANNULEE'
            ], 200);
        }

        // Confirmer — appeler confirmerPaiement
        $push->update([
            'statut' => 'CONFIRME',
            'pin'    => $request->pin
        ]);

        // Réutiliser la logique existante
        $fakeRequest = new \Illuminate\Http\Request();

        $fakeRequest->merge([
            'numero_client' => $push->numero_client,
            'code_momo'     => $request->pin,
            'forcer_echec'  => false,
        ]);

        return $this->confirmerPaiement($fakeRequest, $sessionId);
    }
}

