<?php

namespace App\Http\Controllers;

use App\Models\Commercant;
use App\Models\CompteOperateur;
use App\Models\Transaction;
use App\Models\SessionPaiement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;

class CommercantController extends Controller
{
    //  Récupérer le commerçant connecté 

    private function getCommercant()
    {
        $user = JWTAuth::parseToken()->authenticate();
        return $user->commercant;
    }

    //  Voir son profil complet 

    public function profil()
    {
        $commercant = $this->getCommercant();

        $commercant->load([
            'compteOperateurs.operateur',
            'user',
        ]);

        return response()->json([
            'commercant' => $commercant,
        ], 200);
    }

    // Modifier ses informations personnelles 

    public function mettreAJourProfil(Request $request)
    {
        $commercant = $this->getCommercant();

        $request->validate([
            'nom'            => 'sometimes|string|max:100',
            'prenom'         => 'sometimes|string|max:100',
            'nom_entreprise' => 'sometimes|string|max:150',
            'telephone'      => 'sometimes|string|max:20',
            'type_commerce'  => 'sometimes|string|max:100',
            'ville'          => 'sometimes|string|max:100',
            'ifu'            => 'nullable|string|max:100',
        ]);

        $commercant->update($request->only([
            'nom',
            'prenom',
            'nom_entreprise',
            'telephone',
            'type_commerce',
            'ville',
            'ifu',
        ]));

        return response()->json([
            'message'    => 'Profil mis à jour avec succès.',
            'commercant' => $commercant,
        ], 200);
    }

    // Changer son mot de passe 

    public function changerMotDePasse(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();

        $request->validate([
            'mot_de_passe_actuel'    => 'required|string',
            'nouveau_mot_de_passe'   => 'required|string|min:8|confirmed',
        ]);

        // Vérifier l'ancien mot de passe
        if (!Hash::check($request->mot_de_passe_actuel, $user->mot_de_passe)) {
            return response()->json([
                'message' => 'Mot de passe actuel incorrect.',
            ], 401);
        }

        $user->update([
            'mot_de_passe' => Hash::make($request->nouveau_mot_de_passe),
        ]);

        return response()->json([
            'message' => 'Mot de passe mis à jour avec succès.',
        ], 200);
    }

    //  Voir ses comptes opérateurs 

    public function compteOperateurs()
    {
        $commercant = $this->getCommercant();

        $comptes = $commercant->compteOperateurs()
                              ->with('operateur')
                              ->get();

        return response()->json([
            'comptes' => $comptes,
        ], 200);
    }

    // Modifier un numéro opérateur
    public function mettreAJourCompte(Request $request, string $id)
    {
        $commercant = $this->getCommercant();

        $request->validate([
            'numero' => 'required|string|max:20',
        ]);

        // Vérifier que ce compte appartient bien au commerçant
        $compte = CompteOperateur::where('id', $id)
                                 ->where('commercant_id', $commercant->id)
                                 ->first();

        if (!$compte) {
            return response()->json([
                'message' => 'Compte opérateur introuvable.',
            ], 404);
        }

        $compte->update(['numero' => $request->numero]);

        return response()->json([
            'message' => 'Numéro mis à jour avec succès.',
            'compte'  => $compte->load('operateur'),
        ], 200);
    }

    //  Dashboard — stats du commerçant (RG24) 

    public function dashboard(Request $request)
    {
        $commercant = $this->getCommercant();

        // RG25 : filtre par période
        $periode = $request->query('periode', 'jour'); // jour, semaine, mois

        $debut = match($periode) {
            'semaine' => now()->startOfWeek(),
            'mois'    => now()->startOfMonth(),
            default   => now()->startOfDay(),
        };

        // Récupérer toutes les transactions du commerçant sur la période
        $transactions = Transaction::whereHas('sessionPaiement', function ($q) use ($commercant) {
            $q->where('commercant_id', $commercant->id);
        })
        ->where('created_at', '>=', $debut)
        ->with(['sessionPaiement', 'operateur'])
        ->get();

        $total      = $transactions->count();
        $reussies   = $transactions->where('statut', 'SUCCESS')->count();
        $echouees   = $transactions->where('statut', 'FAILED')->count();
        $enAttente  = $transactions->where('statut', 'EN_ATTENTE')->count();

        // Volume total encaissé (RG24)
        $volumeTotal = $transactions->where('statut', 'SUCCESS')
                                    ->sum(fn($t) => $t->sessionPaiement->montant);

        // Répartition par opérateur
        $parOperateur = $transactions->where('statut', 'SUCCESS')
            ->groupBy(fn($t) => $t->operateur->nom)
            ->map(fn($group) => [
                'count'  => $group->count(),
                'volume' => $group->sum(fn($t) => $t->sessionPaiement->montant),
            ]);

        // Solde total sur tous les comptes opérateurs
        $soldeTotal = $commercant->compteOperateurs()
                                 ->where('actif', true)
                                 ->sum('solde');

        // Transactions des 7 derniers jours pour le graphique
        $stats7Jours = [];
        for ($i = 6; $i >= 0; $i--) {
            $jour = now()->subDays($i)->startOfDay();
            $finJour = now()->subDays($i)->endOfDay();

            $txJour = Transaction::whereHas('sessionPaiement', function ($q) use ($commercant) {
                $q->where('commercant_id', $commercant->id);
            })
            ->whereBetween('created_at', [$jour, $finJour])
            ->get();

            $stats7Jours[] = [
                'jour'      => $jour->format('D'),
                'reussies'  => $txJour->where('statut', 'SUCCESS')->count(),
                'echouees'  => $txJour->where('statut', 'FAILED')->count(),
            ];
        }

        // Transactions récentes (5 dernières)
        $recentes = Transaction::whereHas('sessionPaiement', function ($q) use ($commercant) {
            $q->where('commercant_id', $commercant->id);
        })
        ->with(['sessionPaiement', 'operateur'])
        ->latest('created_at')
        ->take(5)
        ->get();

        return response()->json([
            'periode'       => $periode,
            'solde_total'   => $soldeTotal,
            'stats'         => [
                'total'      => $total,
                'reussies'   => $reussies,
                'echouees'   => $echouees,
                'en_attente' => $enAttente,
                'taux_succes'=> $total > 0 ? round(($reussies / $total) * 100, 1) : 0,
                'volume'     => $volumeTotal,
            ],
            'par_operateur' => $parOperateur,
            'stats_7_jours' => $stats7Jours,
            'recentes'      => $recentes,
        ], 200);
    }
}