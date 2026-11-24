<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Configuración — ApiFacGM</title>
    <style>
        :root {
            --bg: #0f172a;
            --surface: #1e293b;
            --card: #ffffff;
            --border: #e2e8f0;
            --text: #0f172a;
            --muted: #64748b;
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --ok-bg: #dcfce7;
            --ok-text: #166534;
            --warn-bg: #fef9c3;
            --warn-text: #854d0e;
            --err-bg: #fee2e2;
            --err-text: #991b1b;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f1f5f9;
            color: var(--text);
        }

        /* Barra Superior + Menú de Pasos */
        .topbar {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: #fff;
            padding: 18px 24px 0;
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.14);
        }
        .topbar-inner {
            max-width: 1140px;
            margin: 0 auto;
        }
        .brand-row {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }
        .brand-title h1 {
            margin: 0;
            font-size: 20px;
            font-weight: 700;
            letter-spacing: -0.01em;
        }
        .brand-title p {
            margin: 3px 0 0;
            font-size: 13px;
            color: #94a3b8;
        }
        .btn-tester {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #10b981;
            color: #fff;
            text-decoration: none;
            padding: 9px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            transition: background 0.2s ease, transform 0.15s ease;
        }
        .btn-tester:hover {
            background: #059669;
            transform: translateY(-1px);
        }

        /* Menú de Navegación por Pasos */
        .step-nav {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .step-tab {
            appearance: none;
            background: rgba(255, 255, 255, 0.07);
            color: #cbd5e1;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-bottom: none;
            padding: 11px 16px;
            border-radius: 10px 10px 0 0;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
        }
        .step-tab:hover {
            background: rgba(255, 255, 255, 0.13);
            color: #fff;
        }
        .step-tab.active {
            background: #f1f5f9;
            color: #0f172a;
            border-color: transparent;
        }
        .step-num {
            display: inline-grid;
            place-items: center;
            width: 22px;
            height: 22px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            background: rgba(255, 255, 255, 0.16);
            color: #fff;
        }
        .step-tab.active .step-num {
            background: var(--primary);
            color: #fff;
        }

        /* Contenedor Principal */
        .container {
            max-width: 1140px;
            margin: 24px auto 48px;
            padding: 0 24px;
        }

        /* Alertas */
        .alert {
            padding: 15px 18px;
            border-radius: 10px;
            margin-bottom: 20px;
            border: 1px solid transparent;
        }
        .alert-success {
            background: #ecfdf5;
            border-color: #6ee7b7;
            color: #065f46;
        }
        .alert-error {
            background: #fef2f2;
            border-color: #fca5a5;
            color: #991b1b;
        }
        .key-box {
            display: flex;
            gap: 8px;
            margin-top: 10px;
            background: #fff;
            padding: 8px 10px;
            border-radius: 8px;
            border: 1px solid #a7f3d0;
        }
        .key-box input {
            flex: 1;
            border: none;
            outline: none;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 13px;
            color: #0f172a;
            background: transparent;
        }
        .terminal-log {
            margin-top: 10px;
            background: #0f172a;
            color: #e2e8f0;
            padding: 12px 14px;
            border-radius: 8px;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 12px;
            white-space: pre-wrap;
            line-height: 1.45;
        }

        /* Tarjetas y Secciones */
        .step-panel { display: none; }
        .step-panel.active { display: block; animation: fadeIn 0.2s ease; }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(4px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .grid-2 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(460px, 1fr));
            gap: 20px;
        }
        @media (max-width: 540px) {
            .grid-2 { grid-template-columns: 1fr; }
        }

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
            margin-bottom: 22px;
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding-bottom: 14px;
            margin-bottom: 18px;
            border-bottom: 1px solid var(--border);
        }
        .card-header h2 {
            margin: 0;
            font-size: 16px;
            font-weight: 700;
        }
        .card-header span {
            font-size: 12px;
            color: var(--muted);
        }

        /* Vista Previa de Parámetros SIAT */
        .siat-preview {
            background: #0f172a;
            color: #e2e8f0;
            border-radius: 10px;
            padding: 14px 16px;
            margin-top: 14px;
            font-size: 12px;
        }
        .siat-preview-title {
            font-weight: 700;
            color: #38bdf8;
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .siat-params-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 8px;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        }
        .siat-param-item {
            background: rgba(255, 255, 255, 0.06);
            padding: 7px 10px;
            border-radius: 6px;
        }
        .siat-param-item span {
            display: block;
            font-size: 10px;
            color: #94a3b8;
        }
        .siat-param-item strong {
            color: #f8fafc;
            font-size: 12px;
            word-break: break-all;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
        }
        @media (max-width: 680px) {
            .form-grid { grid-template-columns: 1fr; }
        }
        .full { grid-column: 1 / -1; }
        .form-group label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #334155;
            margin-bottom: 5px;
        }
        .form-group small {
            display: block;
            margin-top: 4px;
            font-size: 11px;
            color: var(--muted);
        }
        input, select, textarea {
            width: 100%;
            padding: 9px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 13px;
            font-family: inherit;
            color: var(--text);
            background: #fff;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        }
        textarea {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            resize: vertical;
        }

        .actions {
            margin-top: 18px;
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 10px;
        }
        .btn {
            background: var(--primary);
            color: #fff;
            border: none;
            padding: 10px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s ease;
        }
        .btn:hover { background: var(--primary-hover); }
        .btn-emerald { background: #059669; }
        .btn-emerald:hover { background: #047857; }
        .btn-indigo { background: #4f46e5; }
        .btn-indigo:hover { background: #4338ca; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        .btn-secondary {
            background: #e2e8f0;
            color: #1e293b;
        }
        .btn-secondary:hover { background: #cbd5e1; }

        /* Tablas */
        .table-wrap { overflow-x: auto; }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        th, td {
            padding: 11px 12px;
            border-bottom: 1px solid var(--border);
            text-align: left;
            vertical-align: middle;
        }
        th {
            background: #f8fafc;
            color: #475569;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .badge {
            display: inline-block;
            padding: 3px 9px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
            margin: 2px 2px 2px 0;
        }
        .badge-ok { background: var(--ok-bg); color: var(--ok-text); }
        .badge-warn { background: var(--warn-bg); color: var(--warn-text); }
        .badge-err { background: var(--err-bg); color: var(--err-text); }
    </style>
</head>
<body>

<div class="topbar">
    <div class="topbar-inner">
        <div class="brand-row">
            <div class="brand-title">
                <h1>ApiFacGM — Configuración Rápida Local</h1>
                <p>Flujo guiado en 4 pasos para habilitar sistemas, emisores, cajas y credenciales SIAT</p>
            </div>
            <a href="/test-factura.html" class="btn-tester">Probar Emisión (/test-factura.html) &rarr;</a>
        </div>

        <nav class="step-nav" aria-label="Pasos de configuración">
            <button type="button" class="step-tab active" data-tab="paso-1" onclick="cambiarPaso('paso-1')">
                <span class="step-num">1</span>
                <span>Crear Sistema</span>
            </button>
            <button type="button" class="step-tab" data-tab="paso-2" onclick="cambiarPaso('paso-2')">
                <span class="step-num">2</span>
                <span>Crear Emisor</span>
            </button>
            <button type="button" class="step-tab" data-tab="paso-3" onclick="cambiarPaso('paso-3')">
                <span class="step-num">3</span>
                <span>Crear Caja / PDV</span>
            </button>
            <button type="button" class="step-tab" data-tab="paso-4" onclick="cambiarPaso('paso-4')">
                <span class="step-num">4</span>
                <span>CUIS, CUFD y Catálogos</span>
            </button>
            <button type="button" class="step-tab" data-tab="paso-estado" onclick="cambiarPaso('paso-estado')">
                <span class="step-num">&#10003;</span>
                <span>Estado y Vinculación</span>
            </button>
        </nav>
    </div>
</div>

<main class="container">
    @if(session('success'))
        <div class="alert alert-success">
            <strong>{{ session('success') }}</strong>
            @if(session('generated_apikey'))
                <div style="margin-top: 6px; font-size: 13px;">
                    Copia esta API Key ahora (se almacena hasheada con SHA-256 en <code>sistemas_cliente</code> y se guardó automáticamente para el probador):
                </div>
                <div class="key-box">
                    <input type="text" id="generatedApiKey" value="{{ session('generated_apikey') }}" readonly>
                    <button type="button" class="btn btn-sm">Copiar Key</button>
                </div>
                
            @endif

            @if(session('artisan_output'))
                <div class="terminal-log">{{ session('artisan_output') }}</div>
            @endif
        </div>
    @endif

    @if($errors->any())
        <div class="alert alert-error">
            <strong>Revisa los datos ingresados:</strong>
            <ul style="margin: 6px 0 0 18px; padding: 0;">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <!-- PASO 1: CREAR SISTEMA CLIENTE -->
    <section id="paso-1" class="step-panel active">
        <div class="card">
            <div class="card-header">
                <h2>Paso 1 · Registrar Sistema Cliente y Generar API Key</h2>
                <span>Tabla: <code>sistemas_cliente</code></span>
            </div>
            <form action="{{ route('panel.sistemas.store') }}" method="POST">
                @csrf
                <div class="form-grid">
                    <div class="form-group">
                        <label for="siscod">Código del Sistema (siscod)</label>
                        <input type="text" id="siscod" name="siscod" value="{{ old('siscod') }}" placeholder="Ej. CAJA_PRINCIPAL" required>
                        <small>Identificador corto y único del sistema o caja.</small>
                    </div>
                    <div class="form-group">
                        <label for="sisnom">Nombre del Sistema (sisnom)</label>
                        <input type="text" id="sisnom" name="sisnom" value="{{ old('sisnom') }}" placeholder="Ej. Caja 1 — Punto de Venta" required>
                        <small>Nombre descriptivo para identificarlo fácilmente.</small>
                    </div>
                </div>
                <div class="actions">
                    <button type="submit" class="btn">Crear Sistema y Generar API Key</button>
                    <button type="button" class="btn btn-secondary" onclick="cambiarPaso('paso-2')">Siguiente: Paso 2 &rarr;</button>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="card-header">
                <h2>Sistemas Registrados</h2>
                <span>{{ $sistemas->count() }} registrados</span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Código</th>
                            <th>Nombre</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($sistemas as $sis)
                            <tr>
                                <td>#{{ $sis->sisid }}</td>
                                <td><code>{{ $sis->siscod }}</code></td>
                                <td>{{ $sis->sisnom }}</td>
                                <td>
                                    @if($sis->sisest)
                                        <span class="badge badge-ok">Activo</span>
                                    @else
                                        <span class="badge badge-err">Inactivo</span>
                                    @endif
                                </td>
                                <td style="white-space:nowrap;">
                                    <form action="{{ route('panel.sistemas.regenerar-key', $sis->sisid) }}" method="POST" style="display:inline;">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-emerald">Regenerar Key</button>
                                    </form>

                                    <button type="button" class="btn btn-sm btn-secondary btn-editar-sistema"
                                        data-id="{{ $sis->sisid }}"
                                        data-codigo="{{ $sis->siscod }}"
                                        data-nombre="{{ $sis->sisnom }}"
                                        data-activo="{{ $sis->sisest ? '1' : '0' }}">
                                        Editar
                                    </button>

                                    <form action="{{ route('panel.sistemas.destroy', $sis->sisid) }}" method="POST" style="display:inline;"
                                        onsubmit="return confirm('¿Eliminar el sistema {{ $sis->siscod }}?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm" style="background:#dc2626;">Eliminar</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" style="text-align:center; color:var(--muted);">Aún no hay sistemas registrados.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Formulario inline para editar un sistema existente -->
            <div id="boxEditarSistema" style="display:none; margin-top:18px; padding-top:16px; border-top:1px solid var(--border);">
                <h3 style="margin:0 0 12px; font-size:14px;">Editando Sistema #<span id="editSisIdLabel"></span></h3>
                <form id="formEditarSistema" method="POST">
                    @csrf
                    @method('PUT')
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="edit_siscod">Código (siscod)</label>
                            <input type="text" id="edit_siscod" name="siscod" required>
                        </div>
                        <div class="form-group">
                            <label for="edit_sisnom">Nombre (sisnom)</label>
                            <input type="text" id="edit_sisnom" name="sisnom" required>
                        </div>
                        <div class="form-group full">
                            <label style="display:inline-flex; align-items:center; gap:8px; cursor:pointer;">
                                <input type="checkbox" id="edit_sisest" name="sisest" value="1" style="width:auto;">
                                <span>Sistema activo (<code>sisest</code>)</span>
                            </label>
                        </div>
                    </div>
                    <div class="actions">
                        <button type="button" class="btn btn-sm btn-secondary" onclick="cancelarEdicionSistema()">Cancelar</button>
                        <button type="submit" class="btn btn-sm">Guardar Cambios</button>
                    </div>
                </form>
            </div>
        </div>
    </section>

    <!-- PASO 2: CREAR / ACTUALIZAR EMISOR -->
    <section id="paso-2" class="step-panel">
        <div class="card">
            <div class="card-header">
                <h2>Paso 2 · Registrar o Actualizar Emisor (Empresa / Cliente)</h2>
                <span>Define NIT, Código Sistema SIN, Ambiente, Modalidad y Token</span>
            </div>
            <form action="{{ route('panel.emisores.store') }}" method="POST">
                @csrf
                <div class="form-grid">
                    <div class="form-group full">
                        <label for="sisid">Sistema Cliente Asignado (Exclusividad)</label>
                        <select id="sisid" name="sisid">
                            <option value="">-- Compartido (abierto a cualquier sistema con API key válida) --</option>
                            @foreach($sistemas as $sis)
                                <option value="{{ $sis->sisid }}" {{ old('sisid') == $sis->sisid ? 'selected' : '' }}>
                                    {{ $sis->siscod }} — {{ $sis->sisnom }} (ID #{{ $sis->sisid }})
                                </option>
                            @endforeach
                        </select>
                        <small>Si eliges un sistema, solo ese sistema podrá emitir facturas con este NIT.</small>
                    </div>

                    <div class="form-group">
                        <label for="eminit">NIT Emisor (eminit &rarr; <code>nit</code> en SIAT)</label>
                        <input type="text" id="eminit" name="eminit" value="{{ old('eminit') }}" placeholder="Ej. 3327479013" required>
                    </div>
                    <div class="form-group">
                        <label for="emisis">Código de Sistema SIN (emisis &rarr; <code>codigoSistema</code> en SIAT)</label>
                        <input type="text" id="emisis" name="emisis" value="{{ old('emisis') }}" placeholder="Ej. 374B233CE8BE03F8430F" required>
                    </div>

                    <div class="form-group full">
                        <label for="emirazsoc">Razón Social (emirazsoc)</label>
                        <input type="text" id="emirazsoc" name="emirazsoc" value="{{ old('emirazsoc') }}" placeholder="Ej. Empresa Prueba S.R.L." required>
                    </div>

                    <div class="form-group">
                        <label for="emimun">Municipio (emimun)</label>
                        <input type="text" id="emimun" name="emimun" value="{{ old('emimun', 'La Paz') }}" required>
                    </div>
                    <div class="form-group">
                        <label for="emitel">Teléfono (emitel)</label>
                        <input type="text" id="emitel" name="emitel" value="{{ old('emitel') }}" placeholder="Opcional">
                    </div>

                    <div class="form-group full">
                        <label for="emidir">Dirección (emidir)</label>
                        <input type="text" id="emidir" name="emidir" value="{{ old('emidir') }}" placeholder="Ej. Av. Camacho #1400" required>
                    </div>

                    <div class="form-group">
                        <label for="emisuc">Sucursal Principal (emisuc)</label>
                        <input type="number" id="emisuc" name="emisuc" value="{{ old('emisuc', 0) }}" min="0" required>
                    </div>
                    <div class="form-group">
                        <label for="emipdv">Punto de Venta Principal (emipdv)</label>
                        <input type="number" id="emipdv" name="emipdv" value="{{ old('emipdv', 0) }}" min="0" required>
                    </div>

                    <div class="form-group">
                        <label for="emimod">Modalidad SIAT (emimod &rarr; <code>codigoModalidad</code>)</label>
                        <select id="emimod" name="emimod">
                            <option value="2" {{ old('emimod', 2) == 2 ? 'selected' : '' }}>2 — Computarizada en Línea</option>
                            <option value="1" {{ old('emimod') == 1 ? 'selected' : '' }}>1 — Electrónica en Línea</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="emiamb">Ambiente SIAT (emiamb &rarr; <code>codigoAmbiente</code>)</label>
                        <select id="emiamb" name="emiamb">
                            <option value="2" {{ old('emiamb', 2) == 2 ? 'selected' : '' }}>2 — Piloto / Pruebas</option>
                            <option value="1" {{ old('emiamb') == 1 ? 'selected' : '' }}>1 — Producción</option>
                        </select>
                    </div>

                    <div class="form-group full">
                        <label for="emitoken">Token Delegado SIAT (emitoken)</label>
                        <textarea id="emitoken" name="emitoken" rows="3" placeholder="Pega aquí el token delegado del SIAT" required>{{ old('emitoken') }}</textarea>
                        <small>Se encripta automáticamente al guardarse en la base de datos.</small>
                    </div>
                </div>
                <div class="actions">
                    <button type="button" class="btn btn-secondary" onclick="cambiarPaso('paso-1')">&larr; Paso 1</button>
                    <button type="submit" class="btn">Guardar Emisor</button>
                    <button type="button" class="btn btn-secondary" onclick="cambiarPaso('paso-3')">Siguiente: Paso 3 &rarr;</button>
                </div>
            </form>
        </div>
    </section>

    <!-- PASO 3: CREAR CAJA / PUNTO DE VENTA -->
    <section id="paso-3" class="step-panel">
        <div class="card">
            <div class="card-header">
                <h2>Paso 3 · Crear Caja / Punto de Venta</h2>
                <span>Tablas: <code>puntos_venta</code> + <code>secuencias</code></span>
            </div>
            <p style="font-size: 13px; color: var(--muted); margin-top: 0;">
                Una caja en el sistema solo necesita <strong>Sucursal</strong> (<code>pvsuc</code>), <strong>Punto de Venta</strong> (<code>pvpdv</code>) y su <strong>correlativo inicial</strong> (<code>secultimo</code>). El <code>nit</code>, <code>codigoSistema</code>, <code>codigoModalidad</code> y <code>codigoAmbiente</code> se heredan automáticamente del Emisor elegido.
            </p>
            <form action="{{ route('panel.puntos-venta.store') }}" method="POST">
                @csrf
                <div class="form-grid">
                    <div class="form-group full">
                        <label for="pv_emiid">Emisor (hereda NIT, Código Sistema, Modalidad y Ambiente)</label>
                        <select id="pv_emiid" name="emiid" onchange="actualizarPreviewSiat()" required>
                            <option value="">-- Selecciona un emisor --</option>
                            @foreach($emisores as $emi)
                                <option
                                    value="{{ $emi->emiid }}"
                                    data-nit="{{ $emi->eminit }}"
                                    data-sistema="{{ $emi->emisis }}"
                                    data-ambiente="{{ $emi->emiamb }}"
                                    data-modalidad="{{ $emi->emimod }}"
                                    {{ old('emiid') == $emi->emiid ? 'selected' : '' }}
                                >
                                    NIT {{ $emi->eminit }} — {{ $emi->emirazsoc }} (Amb: {{ $emi->emiamb }} / Mod: {{ $emi->emimod }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="pvsuc">Código de Sucursal (pvsuc &rarr; <code>codigoSucursal</code>)</label>
                        <input type="number" id="pvsuc" name="pvsuc" value="{{ old('pvsuc', 0) }}" min="0" oninput="actualizarPreviewSiat()" required>
                    </div>
                    <div class="form-group">
                        <label for="pvpdv">Código de Punto de Venta / Caja (pvpdv &rarr; <code>codigoPuntoVenta</code>)</label>
                        <input type="number" id="pvpdv" name="pvpdv" value="{{ old('pvpdv', 0) }}" min="0" oninput="actualizarPreviewSiat()" required>
                    </div>
                    <div class="form-group full">
                        <label for="secultimo">Último Número de Factura Emitido (secultimo en <code>secuencias</code>)</label>
                        <input type="number" id="secultimo" name="secultimo" value="{{ old('secultimo', 0) }}" min="0" required>
                        <small>Si empieza desde cero, deja 0 para que la primera factura emitida en esta caja sea la #1.</small>
                    </div>
                </div>

                <!-- Vista Previa de Parámetros SIAT para esta Caja -->
                <div class="siat-preview">
                    <div class="siat-preview-title">
                        <span>Vista Previa de Parámetros SIAT (SolicitudCuis / getBaseParameters)</span>
                        <span>Se envían al pedir CUIS o CUFD para esta caja</span>
                    </div>
                    <div class="siat-params-grid">
                        <div class="siat-param-item"><span>nit</span><strong id="prev_nit">-</strong></div>
                        <div class="siat-param-item"><span>codigoSistema</span><strong id="prev_sistema">-</strong></div>
                        <div class="siat-param-item"><span>codigoAmbiente</span><strong id="prev_ambiente">-</strong></div>
                        <div class="siat-param-item"><span>codigoModalidad</span><strong id="prev_modalidad">-</strong></div>
                        <div class="siat-param-item"><span>codigoSucursal</span><strong id="prev_sucursal">0</strong></div>
                        <div class="siat-param-item"><span>codigoPuntoVenta</span><strong id="prev_pdv">0</strong></div>
                    </div>
                </div>

                <div class="actions">
                    <button type="button" class="btn btn-secondary" onclick="cambiarPaso('paso-2')">&larr; Paso 2</button>
                    <button type="submit" class="btn">Crear Caja y Secuencia</button>
                    <button type="button" class="btn btn-secondary" onclick="cambiarPaso('paso-4')">Siguiente: Paso 4 &rarr;</button>
                </div>
            </form>
        </div>
    </section>

    <!-- PASO 4: CUIS, CUFD Y SINCRONIZACIÓN DE CATÁLOGOS -->
    <section id="paso-4" class="step-panel">
        <div class="grid-2">
            <!-- 4A. CUIS -->
            <div class="card">
                <div class="card-header">
                    <h2>4A · Registrar o Solicitar CUIS</h2>
                    <span>Tabla: <code>siat_codigos</code></span>
                </div>
                <form action="{{ route('panel.siat.cuis') }}" method="POST">
                    @csrf
                    <div class="form-grid">
                        <div class="form-group full">
                            <label>Emisor</label>
                            <select name="emiid" required>
                                @foreach($emisores as $emi)
                                    <option value="{{ $emi->emiid }}">NIT {{ $emi->eminit }} — {{ $emi->emirazsoc }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Sucursal (<code>codigoSucursal</code>)</label>
                            <input type="number" name="sucursal" value="0" min="0" required>
                        </div>
                        <div class="form-group">
                            <label>Punto de Venta (<code>codigoPuntoVenta</code>)</label>
                            <input type="number" name="pdv" value="0" min="0" required>
                        </div>
                        <div class="form-group full">
                            <label>Modo de Obtención</label>
                            <select name="modo" id="modoCuis" onchange="toggleCuisManual()">
                                <option value="manual" selected>Ingresar CUIS manualmente (ya obtenido en portal SIAT)</option>
                                <option value="siat">Solicitar automáticamente al SIAT vía SOAP</option>
                            </select>
                        </div>
                        <div class="form-group" id="grupoCuisValor">
                            <label>Código CUIS</label>
                            <input type="text" name="cuis_manual" placeholder="Ej. 9BDCABFB">
                        </div>
                        <div class="form-group" id="grupoCuisVenc">
                            <label>Fecha de Vigencia / Vencimiento (opcional)</label>
                            <input type="text" name="vencimiento" placeholder="Ej. 2027-11-17 o vacío (+1 año)">
                            <small>No es obligatorio: si lo dejas vacío se asigna automáticamente +1 año.</small>
                        </div>
                    </div>
                    <div class="actions">
                        <button type="submit" class="btn">Guardar / Obtener CUIS</button>
                    </div>
                </form>
            </div>

            <!-- 4B. CUFD + CATÁLOGOS -->
            <div>
                <div class="card">
                    <div class="card-header">
                        <h2>4B · Generar / Renovar CUFD Diario</h2>
                        <span>Requiere CUIS vigente</span>
                    </div>
                    <form action="{{ route('panel.siat.cufd') }}" method="POST">
                        @csrf
                        <div class="form-grid">
                            <div class="form-group full">
                                <label>Emisor</label>
                                <select name="emiid" required>
                                    @foreach($emisores as $emi)
                                        <option value="{{ $emi->emiid }}">NIT {{ $emi->eminit }} — {{ $emi->emirazsoc }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Sucursal (<code>codigoSucursal</code>)</label>
                                <input type="number" name="sucursal" value="0" min="0" required>
                            </div>
                            <div class="form-group">
                                <label>Punto de Venta (<code>codigoPuntoVenta</code>)</label>
                                <input type="number" name="pdv" value="0" min="0" required>
                            </div>
                        </div>
                        <div class="actions">
                            <button type="submit" class="btn btn-emerald">Generar / Renovar CUFD</button>
                        </div>
                    </form>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2>4C · Sincronizar Catálogos y Hora SIN</h2>
                        <span>Actividades, Productos, Leyendas y Unidades</span>
                    </div>
                    <p style="font-size: 13px; color: var(--muted); margin-top: 0;">
                        Descarga y actualiza desde el SIAT las actividades económicas, catálogo de productos/servicios, unidades de medida, leyendas, métodos de pago, monedas y verifica la deriva de reloj oficial.
                    </p>
                    <form action="{{ route('panel.siat.catalogos') }}" method="POST">
                        @csrf
                        <div class="actions">
                            <button type="submit" class="btn btn-indigo">Sincronizar Todos los Catálogos SIAT</button>
                            <a href="/test-factura.html" class="btn btn-secondary" style="text-decoration:none;">Ir a Emitir Factura &rarr;</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </section>

    <!-- RESUMEN DE ESTADO Y VINCULACIÓN -->
    <section id="paso-estado" class="step-panel">
        <div class="card">
            <div class="card-header">
                <h2>Estado Actual de Emisores, Vinculación y Códigos SIAT</h2>
                <span>Revisa CUIS, CUFD y sistema dueño</span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Emisor</th>
                            <th>Sistema Asignado</th>
                            <th>Cajas (Suc / PDV)</th>
                            <th>CUIS / CUFD</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($emisores as $emi)
                            @php
                                $cuis = $codigosVigentes[$emi->emiid]['cuis'] ?? null;
                                $cufd = $codigosVigentes[$emi->emiid]['cufd'] ?? null;
                            @endphp
                            <tr>
                                <td>
                                    <strong>{{ $emi->emirazsoc }}</strong><br>
                                    <small style="color:var(--muted);">NIT: <code>{{ $emi->eminit }}</code> · Sis: <code>{{ $emi->emisis }}</code> · Amb: {{ $emi->emiamb }} · Mod: {{ $emi->emimod }}</small>
                                </td>
                                <td>
                                    <form action="{{ route('panel.emisores.vincular', $emi->emiid) }}" method="POST" style="display:flex; gap:6px; align-items:center;">
                                        @csrf
                                        <select name="sisid" style="padding:6px 8px; font-size:12px;">
                                            <option value="">Compartido (Libre)</option>
                                            @foreach($sistemas as $sis)
                                                <option value="{{ $sis->sisid }}" {{ $emi->sisid == $sis->sisid ? 'selected' : '' }}>
                                                    {{ $sis->siscod }}
                                                </option>
                                            @endforeach
                                        </select>
                                        <button type="submit" class="btn btn-sm">Vincular</button>
                                    </form>
                                </td>
                                <td>
                                    @foreach($emi->puntosVenta as $pv)
                                        <span class="badge badge-ok">Suc {{ $pv->pvsuc }} / PDV {{ $pv->pvpdv }}</span>
                                    @endforeach
                                </td>
                                <td>
                                    @if($cuis)
                                        <span class="badge badge-ok">CUIS: {{ $cuis->scovalor }}</span>
                                    @else
                                        <span class="badge badge-err">Sin CUIS</span>
                                    @endif

                                    @if($cufd)
                                        <span class="badge badge-ok">CUFD hasta {{ \Carbon\Carbon::parse($cufd->scoven)->format('d/m H:i') }}</span>
                                    @else
                                        <span class="badge badge-warn">Sin CUFD</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" style="text-align:center; color:var(--muted);">No hay emisores registrados todavía.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</main>

<script>

    function editarSistema(id, codigo, nombre, activo) {
    const box = document.getElementById('boxEditarSistema');
    const form = document.getElementById('formEditarSistema');
    document.getElementById('editSisIdLabel').textContent = id;
    document.getElementById('edit_siscod').value = codigo;
    document.getElementById('edit_sisnom').value = nombre;
    document.getElementById('edit_sisest').checked = Boolean(activo);
    form.action = `/panel/sistemas/${id}`;
    box.style.display = 'block';
    box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function cancelarEdicionSistema() {
    document.getElementById('boxEditarSistema').style.display = 'none';
}

function cambiarPaso(tabId) {
    document.querySelectorAll('.step-tab').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.tab === tabId);
    });
    document.querySelectorAll('.step-panel').forEach(panel => {
        panel.classList.toggle('active', panel.id === tabId);
    });
    localStorage.setItem('apifacgm_panel_tab', tabId);
}

function toggleCuisManual() {
    const modo = document.getElementById('modoCuis');
    if (!modo) return;
    const isManual = modo.value === 'manual';
    document.getElementById('grupoCuisValor').style.display = isManual ? 'block' : 'none';
    document.getElementById('grupoCuisVenc').style.display = isManual ? 'block' : 'none';
}

function actualizarPreviewSiat() {
    const select = document.getElementById('pv_emiid');
    const sucInput = document.getElementById('pvsuc');
    const pdvInput = document.getElementById('pvpdv');
    if (!select || !sucInput || !pdvInput) return;

    const opt = select.options[select.selectedIndex];
    document.getElementById('prev_nit').textContent = opt?.dataset?.nit || '-';
    document.getElementById('prev_sistema').textContent = opt?.dataset?.sistema || '-';
    document.getElementById('prev_ambiente').textContent = opt?.dataset?.ambiente || '-';
    document.getElementById('prev_modalidad').textContent = opt?.dataset?.modalidad || '-';
    document.getElementById('prev_sucursal').textContent = sucInput.value || '0';
    document.getElementById('prev_pdv').textContent = pdvInput.value || '0';
}

const savedTab = localStorage.getItem('apifacgm_panel_tab');
if (savedTab && document.getElementById(savedTab)) {
    cambiarPaso(savedTab);
}
toggleCuisManual();
actualizarPreviewSiat();
</script>
</body>
</html>