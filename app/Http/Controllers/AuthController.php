<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Commercant;
use App\Models\Operateur;
use App\Models\CompteOperateur;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    //  Inscription commerçant 

    public function register(Request $request)
    {
        $request->validate([
            'email'                   => 'required|email|unique:users,email',
            'mot_de_passe'            => 'required|min:8|confirmed',
            'nom'                     => 'required|string|max:100',
            'prenom'                  => 'required|string|max:100',
            'nom_entreprise'          => 'required|string|max:150',
            'telephone'               => 'required|string|max:20',
            'type_commerce'           => 'required|string|max:100',
            'ville'                   => 'required|string|max:100',
            'ifu'                     => 'nullable|string|max:100',
            'comptes'                 => 'required|array|min:1|max:3',
            'comptes.*.operateur_nom' => 'required|string',
            'comptes.*.numero'        => 'required|string|max:20',
        ]);

        // 1. Créer le compte utilisateur
        $nomsOperateurs = collect($request->comptes)->pluck('operateur_nom')->unique()->values();
        $operateurs = Operateur::whereIn('nom', $nomsOperateurs)
                               ->where('actif', true)
                               ->get()
                               ->keyBy('nom');

        $operateursManquants = $nomsOperateurs->diff($operateurs->keys());

        if ($operateursManquants->isNotEmpty()) {
            return response()->json([
                'message' => 'Certains operateurs sont indisponibles. Lancez les seeders ou reactivez les operateurs.',
                'errors'  => [
                    'comptes' => [
                        'Operateurs manquants: ' . $operateursManquants->implode(', '),
                    ],
                ],
            ], 422);
        }

        DB::transaction(function () use ($request, $operateurs) {
            $user = User::create([
                'email'        => $request->email,
                'mot_de_passe' => Hash::make($request->mot_de_passe),
                'role'         => 'commercant',
            ]);

        // 2. Créer le profil commerçant
            $commercant = Commercant::create([
                'utilisateur_id' => $user->id,
                'nom'            => $request->nom,
                'prenom'         => $request->prenom,
                'nom_entreprise' => $request->nom_entreprise,
                'telephone'      => $request->telephone,
                'ifu'            => $request->ifu,
                'type_commerce'  => $request->type_commerce,
                'ville'          => $request->ville,
            ]);

        // 3. Créer les comptes opérateurs (max 3)
            foreach ($request->comptes as $compte) {
                CompteOperateur::create([
                    'commercant_id' => $commercant->id,
                    'operateur_id'  => $operateurs[$compte['operateur_nom']]->id,
                    'numero'        => $compte['numero'],
                    'actif'         => true,
                    'solde'         => 0,
                ]);

                $nb = CompteOperateur::count();

\Log::info("NB COMPTES OPERATEURS = ".$nb);

                \Log::info('COMPTE CREE', [
    'id' => $nouveauCompte->id,
    'commercant_id' => $nouveauCompte->commercant_id,
]);
            }
            
        });

        // 4. On ne génère pas de token — le commerçant doit se connecter
        return response()->json([
            'message' => 'Compte créé avec succès. Veuillez vous connecter.',
        ], 201);
    }

    //  Connexion

    public function login(Request $request)
    {
        $request->validate([
            'email'        => 'required|email',
            'mot_de_passe' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->mot_de_passe, $user->mot_de_passe)) {
            return response()->json([
                'message' => 'Email ou mot de passe incorrect',
            ], 401);
        }

        $token = JWTAuth::fromUser($user);

        // Sauvegarde le token en base
        $user->update(['token_session' => $token]);

        return response()->json([
            'message' => 'Connexion réussie',
            'token'   => $token,
            'role'    => $user->role,
            'user'    => $user,
        ], 200);
    }

    //  Déconnexion 
    public function logout()
    {
        $user = JWTAuth::parseToken()->authenticate();
        $user->update(['token_session' => null]);
        JWTAuth::invalidate(JWTAuth::getToken());

        return response()->json([
            'message' => 'Déconnexion réussie',
        ], 200);
    }

    //  Utilisateur connecté 

    public function me()
    {
        $user = JWTAuth::parseToken()->authenticate();
        $commercant = $user->commercant;

        return response()->json([
            'user'       => $user,
            'commercant' => $commercant,
        ], 200);
    }
}
