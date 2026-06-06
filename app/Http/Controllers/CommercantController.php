<?php

namespace App\Http\Controllers;

use App\Models\Commercant;
use App\Models\CompteOperateur;
use App\Models\Operateur;
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
        $commercant = $user->commercant;

        if ($commercant?->statut === 'suspendu') {
            abort(403, 'Compte commercant suspendu.');
        }

        return $commercant;
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

    // Ajouter un compte operateur
    public function creerCompte(Request $request)
    {
        $commercant = $this->getCommercant();

        $request->validate([
            'operateur_id'  => 'sometimes|uuid',
            'operateur_nom' => 'required_without:operateur_id|string|max:50',
            'numero'        => 'required|string|max:20',
            'actif'         => 'sometimes|boolean',
        ]);

        if ($commercant->compteOperateurs()->count() >= 3) {
            return response()->json([
                'message' => 'Vous pouvez avoir au maximum 3 comptes mobile money.',
            ], 422);
        }

        $operateur = $this->trouverOperateurActif($request);

        if (!$operateur) {
            return response()->json([
                'message' => 'Operateur indisponible ou introuvable.',
            ], 422);
        }

        $existeDeja = $commercant->compteOperateurs()
                                 ->where('operateur_id', $operateur->id)
                                 ->exists();

        if ($existeDeja) {
            return response()->json([
                'message' => 'Un compte existe deja pour cet operateur.',
            ], 422);
        }

        $actif = $request->has('actif') ? $request->boolean('actif') : true;
        $actifs = $commercant->compteOperateurs()->where('actif', true)->count();

        if (!$actif && $actifs === 0) {
            return response()->json([
                'message' => 'Vous devez garder au moins 1 compte actif.',
            ], 422);
        }

        $compte = CompteOperateur::create([
            'commercant_id' => $commercant->id,
            'operateur_id'  => $operateur->id,
            'numero'        => $request->numero,
            'actif'         => $actif,
            'solde'         => 0,
        ]);

        return response()->json([
            'message' => 'Compte mobile money ajoute avec succes.',
            'compte'  => $compte->load('operateur'),
        ], 201);
    }

    // Modifier un numéro opérateur
    public function mettreAJourCompte(Request $request, string $id)
    {
        $commercant = $this->getCommercant();

        $request->validate([
            'operateur_id'  => 'sometimes|uuid',
            'operateur_nom' => 'sometimes|string|max:50',
            'numero'        => 'sometimes|required|string|max:20',
            'actif'         => 'sometimes|boolean',
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

        $data = [];

        if ($request->filled('numero')) {
            $data['numero'] = $request->numero;
        }

        if ($request->filled('operateur_id') || $request->filled('operateur_nom')) {
            $operateur = $this->trouverOperateurActif($request);

            if (!$operateur) {
                return response()->json([
                    'message' => 'Operateur indisponible ou introuvable.',
                ], 422);
            }

            $existeDeja = $commercant->compteOperateurs()
                                     ->where('operateur_id', $operateur->id)
                                     ->where('id', '!=', $compte->id)
                                     ->exists();

            if ($existeDeja) {
                return response()->json([
                    'message' => 'Un compte existe deja pour cet operateur.',
                ], 422);
            }

            $data['operateur_id'] = $operateur->id;
        }

        if ($request->has('actif')) {
            $prochainStatut = $request->boolean('actif');
            $actifs = $commercant->compteOperateurs()->where('actif', true)->count();

            if (!$prochainStatut && (($compte->actif && $actifs <= 1) || $actifs === 0)) {
                return response()->json([
                    'message' => 'Vous devez garder au moins 1 compte actif.',
                ], 422);
            }

            $data['actif'] = $prochainStatut;
        }

        if (empty($data)) {
            return response()->json([
                'message' => 'Aucune modification fournie.',
                'compte'  => $compte->load('operateur'),
            ], 200);
        }

        $compte->update($data);

        return response()->json([
            'message' => 'Numéro mis à jour avec succès.',
            'compte'  => $compte->load('operateur'),
        ], 200);
    }

    //  Dashboard — stats du commerçant (RG24) 

    private function trouverOperateurActif(Request $request): ?Operateur
    {
        $query = Operateur::where('actif', true);

        if ($request->filled('operateur_id')) {
            return $query->where('id', $request->operateur_id)->first();
        }

        if ($request->filled('operateur_nom')) {
            return $query->where('nom', $request->operateur_nom)->first();
        }

        return null;
    }

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
        $sessionsEnAttente = SessionPaiement::where('commercant_id', $commercant->id)
            ->where('statut', 'EN_ATTENTE')
            ->where('expires_at', '>', now())
            ->whereDoesntHave('transaction')
            ->get();

        $enAttente  = $sessionsEnAttente->count();

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
