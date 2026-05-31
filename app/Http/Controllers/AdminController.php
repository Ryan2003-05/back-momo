<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Commercant;
use App\Models\Transaction;
use App\Models\Operateur;
use App\Models\Notification;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;

class AdminController extends Controller
{
    //  Vérifier que l'utilisateur connecté est admin 

    private function verifierAdmin()
    {
        $user = JWTAuth::parseToken()->authenticate();

        if ($user->role !== 'admin') {
            abort(403, 'Accès réservé aux administrateurs.');
        }

        return $user;
    }

    // Dashboard admin — stats globales 

    public function dashboard()
    {
        $this->verifierAdmin();

        $totalCommercants  = Commercant::count();
        $totalTransactions = Transaction::count();
        $transactionsSucces = Transaction::where('statut', 'SUCCESS')->count();
        $transactionsEchec  = Transaction::where('statut', 'FAILED')->count();

        $volumeTotal = Transaction::where('statut', 'SUCCESS')
            ->with('sessionPaiement')
            ->get()
            ->sum(fn($t) => $t->sessionPaiement->montant ?? 0);

        $tauxSucces = $totalTransactions > 0
            ? round(($transactionsSucces / $totalTransactions) * 100, 1)
            : 0;

        // Stats 7 derniers jours
        $stats7Jours = [];
        for ($i = 6; $i >= 0; $i--) {
            $jour    = now()->subDays($i)->startOfDay();
            $finJour = now()->subDays($i)->endOfDay();

            $txJour = Transaction::whereBetween('created_at', [$jour, $finJour])->get();

            $stats7Jours[] = [
                'jour'      => $jour->format('D'),
                'reussies'  => $txJour->where('statut', 'SUCCESS')->count(),
                'echouees'  => $txJour->where('statut', 'FAILED')->count(),
            ];
        }

        // Statut des opérateurs
        $operateurs = Operateur::withCount([
            'transactions',
            'transactions as transactions_succes_count' => fn($q) => $q->where('statut', 'SUCCESS'),
        ])->get()->map(function ($op) {
            $total  = $op->transactions_count;
            $succes = $op->transactions_succes_count;
            return [
                'id'         => $op->id,
                'nom'        => $op->nom,
                'actif'      => $op->actif,
                'taux_succes'=> $total > 0 ? round(($succes / $total) * 100, 1) : 0,
                'total_txn'  => $total,
            ];
        });

        // Transactions récentes
        $recentes = Transaction::with([
            'sessionPaiement.commercant',
            'operateur',
        ])
        ->latest('created_at')
        ->take(10)
        ->get();

        // Commerçants en attente de validation
        $enAttente = Commercant::whereHas('user', fn($q) => $q->where('role', 'commercant'))
            ->where('created_at', '>=', now()->subDays(7))
            ->with('compteOperateurs.operateur')
            ->latest()
            ->take(5)
            ->get();

        return response()->json([
            'stats' => [
                'total_commercants'   => $totalCommercants,
                'total_transactions'  => $totalTransactions,
                'transactions_succes' => $transactionsSucces,
                'transactions_echec'  => $transactionsEchec,
                'volume_total'        => $volumeTotal,
                'taux_succes'         => $tauxSucces,
            ],
            'stats_7_jours'  => $stats7Jours,
            'operateurs'     => $operateurs,
            'recentes'       => $recentes,
            'en_attente'     => $enAttente,
        ], 200);
    }

    //  Liste de tous les commerçants 

    public function listeCommercants(Request $request)
    {
        $this->verifierAdmin();

        $query = Commercant::with([
            'user',
            'compteOperateurs.operateur',
        ]);

        // Filtre par ville
        if ($request->ville) {
            $query->where('ville', $request->ville);
        }

        // Recherche par nom ou commerce
        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('nom', 'like', '%' . $request->search . '%')
                  ->orWhere('nom_entreprise', 'like', '%' . $request->search . '%');
            });
        }

        $commercants = $query->latest()->paginate(20);

        return response()->json([
            'commercants' => $commercants,
        ], 200);
    }

    //  Voir le détail d'un commerçant 
    public function detailCommercant(string $id)
    {
        $this->verifierAdmin();

        $commercant = Commercant::with([
            'user',
            'compteOperateurs.operateur',
        ])->find($id);

        if (!$commercant) {
            return response()->json(['message' => 'Commerçant introuvable.'], 404);
        }

        $totalTxn   = Transaction::whereHas('sessionPaiement', fn($q) => $q->where('commercant_id', $id))->count();
        $successTxn = Transaction::whereHas('sessionPaiement', fn($q) => $q->where('commercant_id', $id))->where('statut', 'SUCCESS')->count();

        return response()->json([
            'commercant' => $commercant,
            'stats'      => [
                'total_transactions' => $totalTxn,
                'transactions_succes'=> $successTxn,
                'taux_succes'        => $totalTxn > 0 ? round(($successTxn / $totalTxn) * 100, 1) : 0,
            ],
        ], 200);
    }

    //  Suspendre un commerçant 

    public function suspendreCommercant(string $id)
    {
        $this->verifierAdmin();

        $commercant = Commercant::find($id);

        if (!$commercant) {
            return response()->json(['message' => 'Commerçant introuvable.'], 404);
        }

        $commercant->user->update(['token_session' => null]);

        return response()->json([
            'message' => 'Compte commerçant suspendu.',
        ], 200);
    }

    //  Réactiver un commerçant 

    public function reactiverCommercant(string $id)
    {
        $this->verifierAdmin();

        $commercant = Commercant::find($id);

        if (!$commercant) {
            return response()->json(['message' => 'Commerçant introuvable.'], 404);
        }

        return response()->json([
            'message' => 'Compte commerçant réactivé.',
        ], 200);
    }

    //  Liste de toutes les transactions 

    public function toutesTransactions(Request $request)
    {
        $this->verifierAdmin();

        $query = Transaction::with([
            'sessionPaiement.commercant',
            'operateur',
        ])->latest('created_at');

        // Filtre par statut
        if ($request->statut) {
            $query->where('statut', strtoupper($request->statut));
        }

        // Filtre par opérateur
        if ($request->operateur) {
            $query->whereHas('operateur', fn($q) => $q->where('nom', $request->operateur));
        }

        $transactions = $query->paginate(20);

        return response()->json([
            'transactions' => $transactions,
        ], 200);
    }

    //  Liste des opérateurs 

    public function listeOperateurs()
    {
        $this->verifierAdmin();

        $operateurs = Operateur::withCount('transactions')->get();

        return response()->json([
            'operateurs' => $operateurs,
        ], 200);
    }

    //  Activer / Désactiver un opérateur (RG17) 

    public function toggleOperateur(string $id)
    {
        $this->verifierAdmin();

        $operateur = Operateur::find($id);

        if (!$operateur) {
            return response()->json(['message' => 'Opérateur introuvable.'], 404);
        }

        $operateur->update(['actif' => !$operateur->actif]);

        return response()->json([
            'message'   => $operateur->actif
                ? 'Opérateur ' . $operateur->nom . ' activé.'
                : 'Opérateur ' . $operateur->nom . ' désactivé.',
            'operateur' => $operateur,
        ], 200);
    }

    // Logs système 

    public function logs()
    {
        $this->verifierAdmin();

        // Dernières transactions (journal d'activité)
        $logs = Transaction::with([
            'sessionPaiement.commercant',
            'operateur',
        ])
        ->latest('created_at')
        ->take(50)
        ->get()
        ->map(fn($t) => [
            'type'       => 'TRANSACTION',
            'statut'     => $t->statut,
            'commercant' => $t->sessionPaiement->commercant->nom_entreprise ?? 'N/A',
            'operateur'  => $t->operateur->nom ?? 'N/A',
            'montant'    => $t->sessionPaiement->montant ?? 0,
            'reference'  => $t->reference_gateway,
            'date'       => $t->created_at,
        ]);

        return response()->json([
            'logs' => $logs,
        ], 200);
    }
}