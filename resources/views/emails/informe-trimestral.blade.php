<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Informe Trimestral Q{{ $trimestre }} {{ $anio }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f4f7;font-family:Arial,'Helvetica Neue',Helvetica,sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f7;padding:40px 0;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">
                    {{-- Header --}}
                    <tr>
                        <td style="background-color:#0891b2;padding:24px 32px;text-align:center;">
                            <h1 style="color:#ffffff;margin:0;font-size:22px;font-weight:700;">Hawkins Suites</h1>
                            <p style="color:#e0f2fe;margin:4px 0 0;font-size:13px;">Informe Trimestral Q{{ $trimestre }} {{ $anio }}</p>
                        </td>
                    </tr>

                    {{-- Body --}}
                    <tr>
                        <td style="padding:32px;">
                            <p style="font-size:15px;color:#333333;margin:0 0 16px;">
                                Estimado/a equipo de <strong>{{ $asesoria->nombre }}</strong>,
                            </p>

                            <p style="font-size:14px;color:#555555;line-height:1.6;margin:0 0 20px;">
                                Adjunto encontrar&aacute; la documentaci&oacute;n contable del trimestre Q{{ $trimestre }} de {{ $anio }} de <strong>Hawkins Real State SL</strong> (Hawkins Suites).
                            </p>

                            {{-- Attached documents list --}}
                            <p style="font-size:14px;color:#333333;font-weight:600;margin:0 0 8px;">Documentos adjuntos:</p>
                            <ul style="font-size:13px;color:#555555;line-height:1.8;margin:0 0 20px;padding-left:20px;">
                                @if($asesoria->enviar_diario_caja)
                                    <li>Diario de Caja Q{{ $trimestre }} {{ $anio }} (Excel)</li>
                                @endif
                                @if($asesoria->enviar_facturas_emitidas)
                                    <li>Facturas Emitidas Q{{ $trimestre }} {{ $anio }} (Excel)</li>
                                @endif
                                @if($asesoria->enviar_facturas_recibidas)
                                    <li>Facturas Recibidas Q{{ $trimestre }} {{ $anio }} (Excel)</li>
                                @endif
                            </ul>

                            {{-- ZIP download link --}}
                            @if($enlaceZip)
                                <div style="background-color:#f0fdfa;border:1px solid #99f6e4;border-radius:6px;padding:16px;margin:0 0 20px;">
                                    <p style="font-size:13px;color:#0f766e;margin:0 0 8px;font-weight:600;">
                                        Facturas en PDF
                                    </p>
                                    <p style="font-size:13px;color:#555555;margin:0 0 10px;">
                                        Puede descargar todas las facturas en formato PDF desde el siguiente enlace:
                                    </p>
                                    <a href="{{ $enlaceZip }}" style="display:inline-block;background-color:#0891b2;color:#ffffff;text-decoration:none;padding:10px 20px;border-radius:5px;font-size:13px;font-weight:600;">
                                        Descargar Facturas PDF
                                    </a>
                                    <p style="font-size:11px;color:#9ca3af;margin:10px 0 0;">
                                        Este enlace es v&aacute;lido durante 30 d&iacute;as.
                                    </p>
                                </div>
                            @endif

                            <p style="font-size:14px;color:#555555;line-height:1.6;margin:0 0 8px;">
                                Quedamos a su disposici&oacute;n para cualquier aclaraci&oacute;n o informaci&oacute;n adicional que puedan necesitar.
                            </p>

                            <p style="font-size:14px;color:#555555;margin:24px 0 0;">
                                Un cordial saludo,
                            </p>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="background-color:#f8fafc;padding:20px 32px;border-top:1px solid #e5e7eb;">
                            <p style="font-size:13px;color:#0891b2;font-weight:700;margin:0 0 4px;">Hawkins Suites</p>
                            <p style="font-size:11px;color:#9ca3af;margin:0;">Apartamentos Algeciras &bull; Hawkins Real State SL</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
