<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; font-size: 13px; color: #1a1a1a; padding: 40px; }

        .header { text-align: center; margin-bottom: 30px; }
        .logo { font-size: 24px; font-weight: bold; color: #16a34a; }
        .logo span { color: #1e3a5f; }
        .header h2 { font-size: 16px; color: #6b7280; margin-top: 6px; font-weight: normal; }

        .badge { display: inline-block; background: #dcfce7; color: #15803d; padding: 4px 14px; border-radius: 20px; font-size: 12px; font-weight: bold; margin-top: 8px; }

        .montant-box { text-align: center; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px; padding: 20px; margin: 24px 0; }
        .montant-val { font-size: 36px; font-weight: bold; color: #15803d; }
        .montant-lbl { font-size: 12px; color: #6b7280; margin-top: 4px; }

        .section { margin-bottom: 20px; }
        .section-title { font-size: 11px; font-weight: bold; color: #9ca3af; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 10px; padding-bottom: 6px; border-bottom: 1px solid #e5e7eb; }

        .info-row { display: flex; justify-content: space-between; padding: 7px 0; border-bottom: 1px solid #f3f4f6; }
        .info-row:last-child { border: none; }
        .info-key { color: #6b7280; }
        .info-val { font-weight: bold; color: #111827; text-align: right; }

        .ref-box { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 16px; margin-top: 20px; text-align: center; }
        .ref-label { font-size: 11px; color: #9ca3af; margin-bottom: 4px; }
        .ref-val { font-size: 15px; font-weight: bold; color: #111827; font-family: monospace; }

        .footer { text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #e5e7eb; }
        .footer p { font-size: 11px; color: #9ca3af; line-height: 1.6; }
        .footer .brand { font-size: 13px; font-weight: bold; color: #16a34a; margin-bottom: 4px; }
    </style>
</head>
<body>

    <div class="header">
        <div class="logo">Pay<span>com</span></div>
        <h2>Reçu de paiement</h2>
        <div class="badge">✓ Paiement confirmé</div>
    </div>

    <div class="montant-box">
        <div class="montant-val">{{ number_format($montant, 0, ',', ' ') }} FCFA</div>
        <div class="montant-lbl">{{ $libelle }}</div>
    </div>

    <div class="section">
        <div class="section-title">Informations du paiement</div>
        <div class="info-row">
            <span class="info-key">Commerce</span>
            <span class="info-val">{{ $nom_commerce }}</span>
        </div>
        <div class="info-row">
            <span class="info-key">Opérateur</span>
            <span class="info-val">{{ $operateur }}</span>
        </div>
        <div class="info-row">
            <span class="info-key">Numéro client</span>
            <span class="info-val">{{ $numero_client }}</span>
        </div>
        <div class="info-row">
            <span class="info-key">Date d'émission</span>
            <span class="info-val">{{ \Carbon\Carbon::parse($date_emission)->format('d/m/Y à H:i') }}</span>
        </div>
    </div>

    @if(!empty($produits))
    <div class="section">
        <div class="section-title">Détail des produits</div>

        <table style="width:100%;border-collapse:collapse;font-size:12px;">
            <thead>
                <tr style="background:#f0fdf4;border-bottom:1px solid #bbf7d0;">
                    <th style="text-align:left;padding:6px 8px;color:#15803d;">Produit</th>
                    <th style="text-align:center;padding:6px 8px;color:#15803d;">Qté</th>
                    <th style="text-align:right;padding:6px 8px;color:#15803d;">Prix unit.</th>
                    <th style="text-align:right;padding:6px 8px;color:#15803d;">Sous-total</th>
                </tr>
            </thead>

            <tbody>
                @foreach($produits as $produit)
                <tr style="border-bottom:1px solid #f3f4f6;">
                    <td style="padding:6px 8px;">{{ $produit['nom'] }}</td>
                    <td style="padding:6px 8px;text-align:center;">{{ $produit['quantite'] }}</td>
                    <td style="padding:6px 8px;text-align:right;">
                        {{ number_format($produit['prix_unitaire'], 0, ',', ' ') }} F
                    </td>
                    <td style="padding:6px 8px;text-align:right;font-weight:bold;">
                        {{ number_format($produit['quantite'] * $produit['prix_unitaire'], 0, ',', ' ') }} F
                    </td>
                </tr>
                @endforeach
            </tbody>

            <tfoot>
                <tr style="background:#f0fdf4;border-top:2px solid #16a34a;">
                    <td colspan="3" style="padding:8px;text-align:right;font-weight:bold;color:#15803d;">
                        TOTAL
                    </td>
                    <td style="padding:8px;text-align:right;font-weight:bold;color:#15803d;font-size:14px;">
                        {{ number_format($montant, 0, ',', ' ') }} FCFA
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
    @else
    <div class="section">
        <div class="section-title">Détail</div>
        <div class="info-row">
            <span class="info-key">Libellé</span>
            <span class="info-val">{{ $libelle }}</span>
        </div>
    </div>
    @endif

    <div class="ref-box">
        <div class="ref-label">Référence du reçu</div>
        <div class="ref-val">{{ $reference }}</div>

        <div class="ref-label" style="margin-top:6px;">Référence transaction</div>
        <div class="ref-val" style="font-size:12px;">{{ $reference_txn }}</div>
    </div>

    <div class="footer">
        <div class="brand">Paycom</div>
        <p>
            Ce reçu est généré automatiquement par Paycom.<br>
            Il constitue une preuve de paiement valide.<br>
            © 2025 Paycom — Démonstration académique
        </p>
    </div>

</body>
</html>