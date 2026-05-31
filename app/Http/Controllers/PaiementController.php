<?php

namespace App\Http\Controllers;

use App\Models\Commercant;
use App\Models\CompteOperateur;
use App\Models\SessionPaiement;
use App\Models\Transaction;
use App\Models\Recu;
use App\Models\Notification;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Str;

class PaiementController extends Controller
{
    //  Créer une session de paiement 
    public function creerSession(Request $request)
    {
        $request->validate([
            'compte_operateur_id'      => 'required|uuid|exists:compte_operateurs,id',
            'montant'                  => 'required|numeric|min:1', // RG6
            'libelle'                  => 'required|string|max:200', // RG6
            'type_paiement'            => 'required|in:QR_CODE,LIEN,USSD', // RG7
            'numero_client'            => 'required_if:type_paiement,USSD|string|max:20',
            'produits'                 => 'nullable|array', // RG6
            'produits.*.nom'           => 'required_with:produits|string|max:100',
            'produits.*.quantite'      => 'required_with:produits|integer|min:1',
            'produits.*.prix_unitaire' => 'required_with:produits|numeric|min:0',
        ]);

        $user       = JWTAuth::parseToken()->authenticate();
        $commercant = $user->commercant;

        // Vérifier que le compte opérateur appartient bien au commerçant
        $compteOperateur = CompteOperateur::where('id', $request->compte_operateur_id)
                                          ->where('commercant_id', $commercant->id)
                                          ->where('actif', true)
                                          ->first();

        if (!$compteOperateur) {
            return response()->json([
                'message' => 'Compte opérateur invalide ou inactif.',
            ], 403);
        }

        // Créer la session — expire dans 3 minutes (RG8)
        $session = SessionPaiement::create([
            'commercant_id'       => $commercant->id,
            'compte_operateur_id' => $compteOperateur->id,
            'montant'             => $request->montant,
            'libelle'             => $request->libelle,
            'produits'            => $request->produits ?? null,
            'statut'              => 'EN_ATTENTE',
            'type_paiement'       => $request->type_paiement,
            'expires_at'          => now()->addMinutes(3),
            'created_at'          => now(),
        ]);

        // Générer le contenu selon le type
        $contenu = $this->genererContenu($session, $request->numero_client ?? null);

        return response()->json([
            'message'    => 'Session de paiement créée',
            'session'    => $session,
            'contenu'    => $contenu,  // QR code / lien / USSD
            'expires_at' => $session->expires_at,
        ], 201);
    }

    // Générer le contenu selon le type de paiement 

    private function genererContenu(SessionPaiement $session, ?string $numeroClient): array
    {
        switch ($session->type_paiement) {
            case 'QR_CODE':
                // Données encodées dans le QR code
                $data = base64_encode(json_encode([
                    'session_id' => $session->id,
                    'montant'    => $session->montant,
                    'libelle'    => $session->libelle,
                    'expires_at' => $session->expires_at,
                ]));
                return [
                    'type'    => 'QR_CODE',
                    'payload' => $data,
                    'message' => 'Scannez ce QR code pour payer',
                ];

            case 'LIEN':
                $lien = $this->urlGatewayClient($session->id);
                return [
                    'type'    => 'LIEN',
                    'payload' => $lien,
                    'message' => 'Partagez ce lien par WhatsApp ou SMS',
                ];

            case 'USSD':
                return [
                    'type'          => 'USSD',
                    'payload'       => '*144*1*' . $session->montant . '#',
                    'numero_client' => $numeroClient,
                    'message'       => 'Push USSD envoyé au client ' . $numeroClient,
                ];
        }

        return [];
    }

    //  Simuler le paiement (Gateway simulée) 

    public function simulerPaiement(Request $request, string $sessionId)
    {
        $session = SessionPaiement::find($sessionId);

        if (!$session) {
            return response()->json(['message' => 'Session introuvable.'], 404);
        }

        // RG8 : vérifier si la session est expirée
        if ($session->estExpiree()) {
            $session->update(['statut' => 'EXPIREE']);
            return response()->json(['message' => 'Session expirée.'], 410);
        }

        // RG9 : une session ne peut générer qu'une seule transaction
        if ($session->transaction) {
            return response()->json(['message' => 'Cette session a déjà une transaction.'], 409);
        }

        $compteOperateur = $session->compteOperateur;
        $operateur       = $compteOperateur->operateur;

        // RG17 : vérifier que l'opérateur est actif
        if (!$operateur->actif) {
            return response()->json(['message' => 'Opérateur indisponible.'], 503);
        }

        // Simulation : 85% de succès, 15% d'échec
        $succes = rand(1, 100) <= 85;
        $statut = $succes ? 'SUCCESS' : 'FAILED';

        // RG10 : créer la transaction automatiquement
        $transaction = Transaction::create([
            'session_paiement_id' => $session->id,
            'operateur_id'        => $operateur->id,
            'reference_gateway'   => 'TXN-' . strtoupper(Str::random(12)),
            'statut'              => $statut,
            'numero_client'       => $request->numero_client ?? 'N/A',
            'created_at'          => now(),
        ]);

        // Mettre à jour le statut de la session
        $session->update(['statut' => $succes ? 'PAYEE' : 'EN_ATTENTE']);

        // RG14 : si succès → générer reçu + notification
        if ($succes) {
            Recu::create([
                'transaction_id' => $transaction->id,
                'reference'      => 'REC-' . strtoupper(Str::random(8)),
                'montant'        => $session->montant,
                'date_emission'  => now(),
            ]);

            // Mettre à jour le solde du compte opérateur
            $compteOperateur->increment('solde', $session->montant);
        }

        // RG14 : notification dans tous les cas (succès ou échec)
        Notification::create([
            'commercant_id'  => $session->commercant_id,
            'transaction_id' => $transaction->id,
            'message'        => $this->messageNotificationPaiement($session, $operateur->nom, $transaction->numero_client),
            'lue'            => false,
            'created_at'     => now(),
        ]);

        return response()->json([
            'message'     => $succes ? 'Paiement réussi !' : 'Paiement échoué.',
            'statut'      => $statut,
            'transaction' => $transaction,
            'recu'        => $succes ? $transaction->recu : null,
            'notification_message' => $this->messageNotificationPaiement($session, $operateur->nom, $transaction->numero_client),
            'redirect_to' => '/nouveau-paiement',
        ], 200);
    }

    private function messageNotificationPaiement(SessionPaiement $session, string $operateurNom, string $numeroClient = 'N/A'): string
    {
        return 'Vous venez de recevoir un paiement de '
            . number_format((float) $session->montant, 0, ',', ' ')
            . ' FCFA. Numero: '
            . $numeroClient
            . '. Operateur: '
            . $operateurNom
            . '.';
    }

    private function frontendUrl(): string
    {
        return rtrim(config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173')), '/');
    }

    private function urlGatewayClient(string $sessionId): string
    {
        return $this->frontendUrl() . '/gateway/' . $sessionId;
    }

    // Détail d'une session 

    public function detailSession(string $sessionId)
    {
        $user       = JWTAuth::parseToken()->authenticate();
        $commercant = $user->commercant;

        $session = SessionPaiement::with(['compteOperateur.operateur', 'transaction.recu', 'transaction.notification'])
                                  ->where('id', $sessionId)
                                  ->where('commercant_id', $commercant->id)
                                  ->first();

        if (!$session) {
            return response()->json(['message' => 'Session introuvable.'], 404);
        }

        // Vérifier expiration
        if ($session->statut === 'EN_ATTENTE' && $session->estExpiree()) {
            $session->update(['statut' => 'EXPIREE']);
        }

        return response()->json(['session' => $session], 200);
    }
}
