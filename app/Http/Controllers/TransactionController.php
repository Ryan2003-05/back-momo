<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\Recu;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Barryvdh\DomPDF\Facade\Pdf;

class TransactionController extends Controller
{
    //  Récupérer le commerçant connecté 

    private function getCommercant()
    {
        $user = JWTAuth::parseToken()->authenticate();
        return $user->commercant;
    }

    //  Historique des transactions (RG25 : filtre par période) 

    public function historique(Request $request)
    {
        $commercant = $this->getCommercant();

        // Filtres
        $periode  = $request->query('periode', 'tout');
        $statut   = $request->query('statut');
        $operateur = $request->query('operateur');

        $debut = match($periode) {
            'jour'    => now()->startOfDay(),
            'semaine' => now()->startOfWeek(),
            'mois'    => now()->startOfMonth(),
            default   => null,
        };

        $query = Transaction::whereHas('sessionPaiement', function ($q) use ($commercant) {
            $q->where('commercant_id', $commercant->id);
        })
        ->with(['sessionPaiement.compteOperateur.operateur', 'recu'])
        ->latest('created_at');

        // Filtre par période
        if ($debut) {
            $query->where('created_at', '>=', $debut);
        }

        // Filtre par statut
        if ($statut) {
            $statut = strtoupper($statut);

            if ($statut === 'ANNULEE') {
                $query->whereHas('sessionPaiement', function ($q) {
                    $q->where('statut', 'ANNULEE');
                });
            } else {
                $query->where('statut', $statut);
            }
        }

        // Filtre par opérateur
        if ($operateur) {
            $query->whereHas('operateur', function ($q) use ($operateur) {
                $q->where('nom', $operateur);
            });
        }

        $transactions = $query->paginate(15);

        return response()->json([
            'transactions' => $transactions,
        ], 200);
    }

    //  Détail d'une transaction 

    public function detail(string $id)
    {
        $commercant = $this->getCommercant();

        $transaction = Transaction::whereHas('sessionPaiement', function ($q) use ($commercant) {
            $q->where('commercant_id', $commercant->id);
        })
        ->with([
            'sessionPaiement.compteOperateur.operateur',
            'operateur',
            'recu',
        ])
        ->find($id);

        if (!$transaction) {
            return response()->json([
                'message' => 'Transaction introuvable.',
            ], 404);
        }

        return response()->json([
            'transaction' => $transaction,
        ], 200);
    }

    //  Télécharger le reçu PDF (RG20) 

    public function telechargerRecu(string $id)
    {
        $commercant = $this->getCommercant();

        $transaction = Transaction::whereHas('sessionPaiement', function ($q) use ($commercant) {
            $q->where('commercant_id', $commercant->id);
        })
        ->with([
            'sessionPaiement.compteOperateur.operateur',
            'operateur',
            'recu',
        ])
        ->find($id);

        if (!$transaction) {
            return response()->json(['message' => 'Transaction introuvable.'], 404);
        }

        // RG18 : reçu uniquement pour les transactions SUCCESS
        if ($transaction->statut !== 'SUCCESS') {
            return response()->json([
                'message' => 'Aucun reçu disponible pour cette transaction.',
            ], 404);
        }

        if (!$transaction->recu) {
            return response()->json([
                'message' => 'Reçu introuvable.',
            ], 404);
        }

        $session    = $transaction->sessionPaiement;
        $operateur  = $transaction->operateur;
        $commercant = $session->commercant ?? $commercant;

        // Données pour le PDF (RG19)
        $data = [
            'reference'      => $transaction->recu->reference,
            'reference_txn'  => $transaction->reference_gateway,
            'montant'        => $transaction->recu->montant,
            'operateur'      => $operateur->nom,
            'libelle'        => $session->libelle,
            'produits'       => $session->produits ?? [],
            'nom_commerce'   => $commercant->nom_entreprise,
            'numero_client'  => $transaction->numero_client,
            'date_emission'  => $transaction->recu->date_emission,
        ];

        // Générer le PDF
        $pdf = Pdf::loadView('recus.recu', $data);

        return $pdf->download('recu-' . $transaction->recu->reference . '.pdf');
    }
}
