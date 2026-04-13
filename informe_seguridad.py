from reportlab.lib.pagesizes import A4
from reportlab.lib.units import mm, cm
from reportlab.lib.colors import HexColor, white, black
from reportlab.lib.styles import ParagraphStyle
from reportlab.platypus import (
    SimpleDocTemplate, Paragraph, Spacer, Table, TableStyle,
    PageBreak, KeepTogether
)
from reportlab.lib.enums import TA_LEFT, TA_CENTER, TA_RIGHT
from reportlab.pdfgen import canvas
import datetime

# Colors
PRIMARY = HexColor("#1a1a2e")
ACCENT = HexColor("#e94560")
ACCENT_GREEN = HexColor("#0f9b58")
ACCENT_ORANGE = HexColor("#f39c12")
LIGHT_BG = HexColor("#f5f5f5")
DARK_TEXT = HexColor("#222222")
GRAY = HexColor("#666666")
LIGHT_GRAY = HexColor("#e0e0e0")
TABLE_HEADER_BG = HexColor("#1a1a2e")
TABLE_ALT_BG = HexColor("#f8f8f8")
RED_BG = HexColor("#fde8e8")
GREEN_BG = HexColor("#e8fde8")

# Styles
title_style = ParagraphStyle("Title", fontName="Helvetica-Bold", fontSize=24, textColor=PRIMARY, spaceAfter=6)
subtitle_style = ParagraphStyle("Subtitle", fontName="Helvetica", fontSize=12, textColor=GRAY, spaceAfter=20)
h1_style = ParagraphStyle("H1", fontName="Helvetica-Bold", fontSize=16, textColor=ACCENT, spaceBefore=20, spaceAfter=10)
h2_style = ParagraphStyle("H2", fontName="Helvetica-Bold", fontSize=13, textColor=PRIMARY, spaceBefore=14, spaceAfter=8)
h3_style = ParagraphStyle("H3", fontName="Helvetica-Bold", fontSize=11, textColor=DARK_TEXT, spaceBefore=10, spaceAfter=6)
body_style = ParagraphStyle("Body", fontName="Helvetica", fontSize=9, textColor=DARK_TEXT, spaceAfter=4, leading=13)
small_style = ParagraphStyle("Small", fontName="Helvetica", fontSize=8, textColor=GRAY, spaceAfter=2, leading=10)
bold_style = ParagraphStyle("Bold", fontName="Helvetica-Bold", fontSize=9, textColor=DARK_TEXT, spaceAfter=4)
cell_style = ParagraphStyle("Cell", fontName="Helvetica", fontSize=7.5, textColor=DARK_TEXT, leading=10)
cell_bold = ParagraphStyle("CellBold", fontName="Helvetica-Bold", fontSize=7.5, textColor=DARK_TEXT, leading=10)
cell_header = ParagraphStyle("CellHeader", fontName="Helvetica-Bold", fontSize=7.5, textColor=white, leading=10)
red_cell = ParagraphStyle("RedCell", fontName="Helvetica-Bold", fontSize=7.5, textColor=ACCENT, leading=10)
green_cell = ParagraphStyle("GreenCell", fontName="Helvetica-Bold", fontSize=7.5, textColor=ACCENT_GREEN, leading=10)


def make_table(headers, rows, col_widths=None):
    """Create a styled table."""
    header_row = [Paragraph(h, cell_header) for h in headers]
    data = [header_row]
    for row in rows:
        data.append([Paragraph(str(c), cell_style) if not isinstance(c, Paragraph) else c for c in row])

    if col_widths is None:
        col_widths = [170 * mm / len(headers)] * len(headers)

    t = Table(data, colWidths=col_widths, repeatRows=1)
    style_cmds = [
        ("BACKGROUND", (0, 0), (-1, 0), TABLE_HEADER_BG),
        ("TEXTCOLOR", (0, 0), (-1, 0), white),
        ("FONTNAME", (0, 0), (-1, 0), "Helvetica-Bold"),
        ("FONTSIZE", (0, 0), (-1, -1), 7.5),
        ("ALIGN", (0, 0), (-1, -1), "LEFT"),
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("GRID", (0, 0), (-1, -1), 0.5, LIGHT_GRAY),
        ("TOPPADDING", (0, 0), (-1, -1), 4),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 4),
        ("LEFTPADDING", (0, 0), (-1, -1), 5),
        ("RIGHTPADDING", (0, 0), (-1, -1), 5),
    ]
    for i in range(1, len(data)):
        if i % 2 == 0:
            style_cmds.append(("BACKGROUND", (0, i), (-1, i), TABLE_ALT_BG))
    t.setStyle(TableStyle(style_cmds))
    return t


def make_result_table(before_after_data):
    """Create a before/after results table."""
    header_row = [
        Paragraph("Metrica", cell_header),
        Paragraph("Antes", cell_header),
        Paragraph("Despues", cell_header),
    ]
    data = [header_row]
    for metric, before, after in before_after_data:
        data.append([
            Paragraph(metric, cell_bold),
            Paragraph(str(before), red_cell),
            Paragraph(str(after), green_cell),
        ])
    t = Table(data, colWidths=[60 * mm, 50 * mm, 50 * mm])
    t.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, 0), TABLE_HEADER_BG),
        ("GRID", (0, 0), (-1, -1), 0.5, LIGHT_GRAY),
        ("TOPPADDING", (0, 0), (-1, -1), 5),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 5),
        ("LEFTPADDING", (0, 0), (-1, -1), 6),
        ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
    ]))
    return t


def footer(canvas_obj, doc):
    canvas_obj.saveState()
    canvas_obj.setFont("Helvetica", 7)
    canvas_obj.setFillColor(GRAY)
    canvas_obj.drawString(20 * mm, 10 * mm, "Informe de Seguridad - Hawcert")
    canvas_obj.drawRightString(190 * mm, 10 * mm, f"Pagina {doc.page}")
    canvas_obj.setStrokeColor(LIGHT_GRAY)
    canvas_obj.line(20 * mm, 13 * mm, 190 * mm, 13 * mm)
    canvas_obj.restoreState()


def build_pdf():
    output_path = "D:/proyectos/programasivan/hawcert-ivan/hawcert-ivan/Informe_Seguridad_11042026.pdf"
    doc = SimpleDocTemplate(
        output_path,
        pagesize=A4,
        leftMargin=20 * mm,
        rightMargin=20 * mm,
        topMargin=20 * mm,
        bottomMargin=20 * mm,
    )

    story = []

    # ===== COVER =====
    story.append(Spacer(1, 40 * mm))
    story.append(Paragraph("Informe de Seguridad", title_style))
    story.append(Paragraph("Auditoria y remediacion de servidores", subtitle_style))
    story.append(Spacer(1, 10 * mm))

    cover_info = [
        ["Fecha", "11 de abril de 2026"],
        ["Servidores", "Externo (217.160.39.81) / Interno (217.160.39.79)"],
        ["Plataforma", "Coolify + Docker + Traefik"],
        ["Realizado por", "Claude (Anthropic)"],
    ]
    cover_table = Table(
        [[Paragraph(r[0], cell_bold), Paragraph(r[1], cell_style)] for r in cover_info],
        colWidths=[40 * mm, 120 * mm],
    )
    cover_table.setStyle(TableStyle([
        ("GRID", (0, 0), (-1, -1), 0.5, LIGHT_GRAY),
        ("TOPPADDING", (0, 0), (-1, -1), 6),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 6),
        ("LEFTPADDING", (0, 0), (-1, -1), 8),
        ("BACKGROUND", (0, 0), (0, -1), LIGHT_BG),
    ]))
    story.append(cover_table)
    story.append(PageBreak())

    # ===== RESUMEN EJECUTIVO =====
    story.append(Paragraph("1. Resumen Ejecutivo", h1_style))
    story.append(Paragraph(
        "Se detecto que el servidor externo presentaba una carga de CPU del 99% con un load average de 47 "
        "(en un servidor de 24 cores), dejando los sitios web inaccesibles. Tras la investigacion se identificaron "
        "multiples vectores de ataque activos, malware instalado en varios contenedores WordPress, y configuraciones "
        "de seguridad deficientes. Se procedieron a aplicar medidas correctivas inmediatas en ambos servidores.",
        body_style
    ))

    story.append(Paragraph("Hallazgos principales:", bold_style))
    hallazgos = [
        "Ataques de bots agresivos (Meta crawler, SEranking, Ahrefs) saturando la CPU",
        "Ataques de fuerza bruta distribuidos a wp-login.php en multiples sitios",
        "Malware activo: python2.64 (cryptominer/botnet), Alfa Shell (webshell), y archivos PHP maliciosos",
        "Plugin wp-file-manager como vector principal de entrada de malware",
        "~1,100 archivos PHP maliciosos en carpetas de uploads",
        "60 contenedores phpMyAdmin consumiendo 228% CPU innecesariamente",
        "xmlrpc.php expuesto en todos los WordPress (vector de fuerza bruta)",
        "Plugins vulnerables: RevSlider (30 sitios), Duplicator backups expuestos (20 sitios)",
    ]
    for h in hallazgos:
        story.append(Paragraph(f"  - {h}", small_style))

    story.append(PageBreak())

    # ===== SERVIDOR EXTERNO =====
    story.append(Paragraph("2. Servidor Externo (217.160.39.81)", h1_style))
    story.append(Paragraph("Estado inicial: CPU 99%, Load 47, servidor practicamente caido.", body_style))

    # Resultado
    story.append(Paragraph("2.1 Resultado", h2_style))
    story.append(make_result_table([
        ("CPU", "99%", "27%"),
        ("Load Average", "47", "13"),
        ("Contenedores", "287", "220"),
        ("Malware activo", "3 sitios", "0"),
        ("PHP maliciosos", "~1,100 archivos", "0"),
        ("Plugins vulnerables", "60+", "0"),
    ]))

    # Acciones globales
    story.append(Paragraph("2.2 Acciones globales (85 WordPress)", h2_style))
    story.append(make_table(
        ["Accion", "Alcance"],
        [
            ["Bloqueo de bots agresivos (.htaccess)", "85 WordPress"],
            ["Bloqueo de xmlrpc.php", "85 WordPress"],
            ["Proteccion wp-login.php (HTTP Basic Auth)", "85 WordPress"],
            ["Proteccion uploads (bloqueo ejecucion PHP)", "85 WordPress"],
            ["Bloqueo REST API enumeracion usuarios", "85 WordPress"],
            ["Bloqueo spam de comentarios", "85 WordPress"],
            ["Eliminacion RevSlider", "30 WordPress"],
            ["Eliminacion Duplicator backups", "20 WordPress"],
            ["Eliminacion WP-Reset", "5 WordPress"],
            ["Eliminacion wp-file-manager", "5 WordPress"],
            ["Rate limiting en Traefik", "Global"],
            ["Fail2ban (4 jails: login, xmlrpc, scanner, ssh)", "Global"],
            ["Bloqueo trafico saliente de WordPress", "85 WordPress"],
            ["60 phpMyAdmin parados", "60 contenedores"],
        ],
        col_widths=[120 * mm, 50 * mm],
    ))

    story.append(PageBreak())

    # Acciones por sitio - Malware
    story.append(Paragraph("2.3 Sitios con malware activo", h2_style))
    story.append(make_table(
        ["Sitio", "Problema", "Accion"],
        [
            ["benitezpaublete.com", "Malware python2.64 + Alfa Shell. Vector: wp-file-manager", "Malware eliminado, plugin eliminado, /tmp limpiado (21,145 archivos)"],
            ["sushipandachiclana", "Alfa Shell en /tmp + 13 PHP en uploads + wp-file-manager", "Alfa Shell eliminada, PHP limpiados, wp-file-manager eliminado"],
            ["cerveceriagarrapata.com", "21 PHP en /tmp + 44 PHP en uploads", "Todo limpiado"],
        ],
        col_widths=[45 * mm, 65 * mm, 60 * mm],
    ))

    # Sitios con ataques
    story.append(Paragraph("2.4 Sitios bajo ataque activo", h2_style))
    story.append(make_table(
        ["Sitio", "Tipo de ataque", "Accion"],
        [
            ["padelbrandspain.com", "CPU 245%. Bombardeo Meta crawler + SEranking", "Bots bloqueados. CPU bajo a 7%"],
            ["mariadelrosariogranados.com", "CPU 77%. Escaner de vulnerabilidades + ataque xmlrpc", "IP bloqueada, xmlrpc bloqueado. CPU bajo a 0%"],
            ["descaradaonline.com", "CPU 30%. Fuerza bruta distribuida a wp-login.php", "wp-login protegido con HTTP auth"],
            ["hawkins.es", "CPU 31%. Procesos Apache acumulados", "Reiniciado, xmlrpc bloqueado"],
        ],
        col_widths=[50 * mm, 60 * mm, 60 * mm],
    ))

    story.append(PageBreak())

    # PHP maliciosos en uploads
    story.append(Paragraph("2.5 Sitios con PHP malicioso en uploads", h2_style))
    story.append(Paragraph("Los archivos PHP en /wp-content/uploads/ son backdoors o webshells. No deberia haber ningun archivo PHP en esa carpeta.", small_style))

    php_sites = [
        ["yasminagarcia.com", "102"], ["kit-digital", "65"], ["talleres-cardosa", "65"],
        ["boutiquejamonceutacom", "65"], ["multiserveisismerx.com", "59"],
        ["dentistsotogrande", "58"], ["construccionesyreformasal.com", "50"],
        ["viviendassandwich.com", "45"], ["marialuisapodologia.com", "44"],
        ["boutiqueceutajamon.com", "44"], ["notariaplazaalta.com", "44"],
        ["notariaalgecirasalta.com", "44"], ["expendedorasordoñez.com", "44"],
        ["aselen.com", "39"], ["abrilfloresmil (hawkins)", "29"],
        ["eurocopisteria", "17"], ["prueba-ivan-mefle", "17"],
        ["heladeria-la-martina", "14"], ["antonio-ariza", "11"],
        ["euroshipspain.com", "7 (root)"], ["padelbrandspain.com", "6"],
        ["otros (9 sitios)", "1-6 c/u"],
    ]
    story.append(make_table(
        ["Sitio", "PHP eliminados"],
        php_sites,
        col_widths=[110 * mm, 60 * mm],
    ))
    story.append(Paragraph("Todos eliminados y carpeta uploads protegida con .htaccess anti-ejecucion PHP.", small_style))

    # Contenedores parados
    story.append(Paragraph("2.6 Contenedores parados", h2_style))
    story.append(make_table(
        ["Contenedor", "Motivo"],
        [
            ["60 phpMyAdmin", "Consumian 228% CPU con 1,332 procesos php-fpm acumulados"],
            ["serlobo (Laravel)", "Error 500 en bucle, 103% CPU"],
            ["avantconsultores.es", "Certificado SSL en bucle de renovacion fallida"],
            ["feralmansa.com", "Certificado SSL en bucle de renovacion fallida"],
            ["centinela", "Parado por peticion"],
        ],
        col_widths=[60 * mm, 110 * mm],
    ))

    story.append(PageBreak())

    # ===== SERVIDOR INTERNO =====
    story.append(Paragraph("3. Servidor Interno (217.160.39.79)", h1_style))
    story.append(Paragraph("Estado inicial: Load 2.93, CPU baja pero con problemas puntuales.", body_style))

    story.append(Paragraph("3.1 Resultado", h2_style))
    story.append(make_result_table([
        ("Load Average", "2.93", "1.20"),
        ("Contenedores", "55", "52"),
        ("Malware", "1 sitio", "0"),
    ]))

    story.append(Paragraph("3.2 Acciones por sitio", h2_style))
    story.append(make_table(
        ["Sitio", "Problema", "Accion"],
        [
            ["herabeautyweb", "CPU 104% (MariaDB). 13 PHP en uploads, wp-file-manager, RevSlider, ataque brute force, tabla corrupta", "Tabla arreglada, malware limpiado, plugins eliminados. Parado por no usarse"],
            ["findpartners", "1 PHP en uploads, sin proteccion, xmlrpc abierto", "Protegido y limpiado"],
            ["nexdirectory", "1 PHP en uploads, sin proteccion, xmlrpc abierto", "Protegido y limpiado"],
        ],
        col_widths=[35 * mm, 70 * mm, 65 * mm],
    ))

    story.append(Paragraph("3.3 Acciones globales", h2_style))
    story.append(make_table(
        ["Accion", "Alcance"],
        [
            ["Bloqueo bots + xmlrpc + wp-login", "3 WordPress"],
            ["Proteccion uploads", "3 WordPress"],
            ["Fail2ban (4 jails)", "Global"],
            ["Rate limiting Traefik", "Global"],
            ["Bloqueo trafico saliente WordPress", "3 WordPress"],
        ],
        col_widths=[120 * mm, 50 * mm],
    ))

    story.append(PageBreak())

    # ===== MEDIDAS DE SEGURIDAD =====
    story.append(Paragraph("4. Medidas de Seguridad Implementadas", h1_style))

    story.append(Paragraph("4.1 Proteccion a nivel de aplicacion (.htaccess)", h2_style))
    protections = [
        ["xmlrpc.php bloqueado", "Devuelve 403 Forbidden. Elimina el vector de fuerza bruta mas comun en WordPress"],
        ["Bots agresivos bloqueados", "meta-externalagent, SERankingBot, AhrefsBot, DotBot, MJ12bot, SemrushBot"],
        ["wp-login.php con HTTP Basic Auth", "Requiere usuario/contrasena adicional antes de llegar al login de WordPress"],
        ["Uploads protegidos", ".htaccess que impide ejecucion de PHP en /wp-content/uploads/"],
        ["REST API usuarios bloqueada", "Impide enumeracion de usuarios via /wp-json/wp/v2/users"],
        ["Spam de comentarios bloqueado", "Bloquea POST a wp-comments-post.php sin referer valido"],
    ]
    story.append(make_table(
        ["Proteccion", "Descripcion"],
        protections,
        col_widths=[60 * mm, 110 * mm],
    ))

    story.append(Paragraph("4.2 Proteccion a nivel de servidor", h2_style))
    server_protections = [
        ["Rate limiting (Traefik)", "50 peticiones/segundo por IP, burst 100"],
        ["Fail2ban - wordpress-login", "5 intentos fallidos = ban 1 hora"],
        ["Fail2ban - wordpress-xmlrpc", "2 intentos = ban 24 horas"],
        ["Fail2ban - wordpress-scanner", "10 escaneos sospechosos = ban 1 hora"],
        ["Fail2ban - sshd", "Proteccion SSH por defecto"],
        ["Trafico saliente bloqueado", "Los WordPress no pueden iniciar conexiones a internet. Si entra malware, no puede comunicarse con servidores de control externos"],
    ]
    story.append(make_table(
        ["Medida", "Configuracion"],
        server_protections,
        col_widths=[60 * mm, 110 * mm],
    ))

    story.append(Paragraph("4.3 Plugins eliminados por vulnerabilidades", h2_style))
    plugins_removed = [
        ["wp-file-manager", "5 sitios", "Permite subida de archivos arbitrarios sin autenticacion. Vector principal de entrada de malware"],
        ["RevSlider", "30 sitios", "Vulnerabilidades historicas de subida de archivos y lectura de archivos del servidor"],
        ["Duplicator backups (.bak)", "20 sitios", "Backups expuestos que pueden contener credenciales de base de datos"],
        ["WP-Reset", "5 sitios", "Permite resetear la base de datos si se explota"],
    ]
    story.append(make_table(
        ["Plugin", "Sitios", "Riesgo"],
        plugins_removed,
        col_widths=[45 * mm, 25 * mm, 100 * mm],
    ))

    story.append(PageBreak())

    # ===== RECOMENDACIONES =====
    story.append(Paragraph("5. Recomendaciones", h1_style))

    recs = [
        ["benitezpaublete.com", "Requiere redeploy limpio desde Coolify. El contenedor actual tiene malware persistente con multiples procesos que se reinstalan automaticamente"],
        ["Actualizaciones WordPress", "Mantener todos los WordPress actualizados. La version actual (6.9.4) es correcta"],
        ["Plugins", "Auditar plugins regularmente. No instalar wp-file-manager, RevSlider de fuentes no oficiales, ni Duplicator sin limpiar backups"],
        ["Contrasenas", "Cambiar contrasenas de admin en todos los WordPress, especialmente los que sufrieron ataques de fuerza bruta"],
        ["phpMyAdmin", "Mantener parados los phpMyAdmin. Solo arrancarlos cuando se necesiten y pararlos despues"],
        ["Monitoring", "Implementar un sistema de monitoring continuo (ej: Uptime Kuma) para detectar anomalias de CPU/trafico"],
        ["Backups", "Verificar que existen backups automaticos de las bases de datos de todos los sitios"],
        ["Certificados SSL", "Revisar avantconsultores.es y feralmansa.com - los dominios no apuntan al servidor, lo que causa bucles de renovacion"],
    ]
    story.append(make_table(
        ["Area", "Recomendacion"],
        recs,
        col_widths=[50 * mm, 120 * mm],
    ))

    # Build
    doc.build(story, onFirstPage=footer, onLaterPages=footer)
    print(f"PDF generado: {output_path}")


if __name__ == "__main__":
    build_pdf()
