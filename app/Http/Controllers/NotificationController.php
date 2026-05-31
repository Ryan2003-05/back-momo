<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;

class NotificationController extends Controller
{
    //  Récupérer le commerçant connecté 

    private function getCommercant()
    {
        $user = JWTAuth::parseToken()->authenticate();
        return $user->commercant;
    }

    //  Liste des notifications (RG22) 
    public function liste()
    {
        $commercant = $this->getCommercant();

        $notifications = Notification::where('commercant_id', $commercant->id)
            ->with(['transaction.sessionPaiement.compteOperateur.operateur'])
            ->latest('created_at')
            ->paginate(20);

        return response()->json([
            'notifications'     => $notifications,
            'non_lues'          => Notification::where('commercant_id', $commercant->id)
                                               ->where('lue', false)
                                               ->count(),
        ], 200);
    }

    //  Marquer une notification comme lue (RG22) 

    public function marquerLue(string $id)
    {
        $commercant = $this->getCommercant();

        $notification = Notification::where('id', $id)
                                    ->where('commercant_id', $commercant->id)
                                    ->first();

        if (!$notification) {
            return response()->json([
                'message' => 'Notification introuvable.',
            ], 404);
        }

        $notification->update(['lue' => true]);

        return response()->json([
            'message'      => 'Notification marquée comme lue.',
            'notification' => $notification,
        ], 200);
    }

    //  Marquer toutes les notifications comme lues 

    public function marquerToutesLues()
    {
        $commercant = $this->getCommercant();

        Notification::where('commercant_id', $commercant->id)
                    ->where('lue', false)
                    ->update(['lue' => true]);

        return response()->json([
            'message' => 'Toutes les notifications ont été marquées comme lues.',
        ], 200);
    }
}
