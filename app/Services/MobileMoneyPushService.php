<?php

namespace App\Services;

use App\Models\PushRequest;
use App\Models\SessionPaiement;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MobileMoneyPushService
{
    public function send(SessionPaiement $session, PushRequest $push): array
    {
        $provider = config('services.mobile_money_push.provider', 'simulation');

        return match ($provider) {
            'mtn'     => $this->sendMtnRequestToPay($session, $push),
            'generic' => $this->sendGenericPush($session, $push),
            default   => [
                'provider' => 'simulation',
                'external_reference' => $push->id,
                'payload' => ['mode' => 'simulation'],
            ],
        };
    }

    private function sendMtnRequestToPay(SessionPaiement $session, PushRequest $push): array
    {
        $config = config('services.mobile_money_push.mtn');
        $reference = (string) Str::uuid();
        $baseUrl = rtrim((string) $config['base_url'], '/');

        $tokenResponse = Http::withHeaders([
            'Ocp-Apim-Subscription-Key' => $config['subscription_key'],
        ])
            ->withBasicAuth($config['api_user'], $config['api_key'])
            ->post($baseUrl . '/collection/token/');

        $tokenResponse->throw();

        $token = $tokenResponse->json('access_token');

        if (!$token) {
            throw new RequestException($tokenResponse);
        }

        $payload = [
            'amount' => (string) $session->montant,
            'currency' => $config['currency'],
            'externalId' => $push->id,
            'payer' => [
                'partyIdType' => 'MSISDN',
                'partyId' => $this->normalizeMsisdn($push->numero_client),
            ],
            'payerMessage' => $session->libelle,
            'payeeNote' => $session->commercant->nom_entreprise ?? 'PayPME',
        ];

        $request = Http::withToken($token)
            ->withHeaders([
                'X-Reference-Id' => $reference,
                'X-Target-Environment' => $config['target_environment'],
                'Ocp-Apim-Subscription-Key' => $config['subscription_key'],
            ]);

        if (!empty($config['callback_url'])) {
            $request = $request->withHeaders(['X-Callback-Url' => $config['callback_url']]);
        }

        $response = $request->post($baseUrl . '/collection/v1_0/requesttopay', $payload);
        $response->throw();

        return [
            'provider' => 'mtn',
            'external_reference' => $reference,
            'payload' => $payload,
        ];
    }

    private function sendGenericPush(SessionPaiement $session, PushRequest $push): array
    {
        $config = config('services.mobile_money_push.generic');
        $reference = $push->id;
        $callbackUrl = rtrim((string) config('app.url'), '/') . '/api/mobile-money/callback/generic';

        $payload = [
            'reference' => $reference,
            'phone' => $this->normalizeMsisdn($push->numero_client),
            'amount' => (string) $session->montant,
            'currency' => $config['currency'],
            'merchant' => $session->commercant->nom_entreprise ?? 'PayPME',
            'description' => $session->libelle,
            'callback_url' => $callbackUrl,
        ];

        $response = Http::withToken((string) $config['token'])
            ->acceptJson()
            ->post((string) $config['endpoint'], $payload);

        $response->throw();

        return [
            'provider' => 'generic',
            'external_reference' => $response->json('reference') ?? $reference,
            'payload' => $response->json() ?? $payload,
        ];
    }

    private function normalizeMsisdn(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '01') && strlen($digits) === 10) {
            return '229' . substr($digits, 2);
        }

        if (strlen($digits) === 8) {
            return '229' . $digits;
        }

        return $digits;
    }
}
