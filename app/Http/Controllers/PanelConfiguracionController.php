<?php

namespace App\Http\Controllers;

use App\Models\Emisor;
use App\Models\PuntoVenta;
use App\Models\Secuencia;
use App\Models\SiatCodigo;
use App\Models\SistemaCliente;
use App\Services\SiatService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class PanelConfiguracionController extends Controller
{
    public function index(): View
    {
        abort_unless(app()->environment('local'), 404);

        $sistemas = SistemaCliente::orderBy('sisid', 'desc')->get();
        $emisores = Emisor::with(['sistema', 'puntosVenta'])->orderBy('emiid', 'desc')->get();

        $codigosVigentes = [];
        foreach ($emisores as $emisor) {
            $cuis = SiatCodigo::where('emiid', $emisor->emiid)
                ->whereIn('scotipo', ['CUIS', 'cuis'])
                ->where('scoest', true)
                ->where('scoven', '>', now())
                ->latest('scoid')
                ->first();

            $cufd = SiatCodigo::where('emiid', $emisor->emiid)
                ->whereIn('scotipo', ['CUFD', 'cufd'])
                ->where('scoest', true)
                ->where('scoven', '>', now())
                ->latest('scoid')
                ->first();

            $codigosVigentes[$emisor->emiid] = [
                'cuis' => $cuis,
                'cufd' => $cufd,
            ];
        }

        return view('panel.configuracion', compact('sistemas', 'emisores', 'codigosVigentes'));
    }

    public function storeSistema(Request $request): RedirectResponse
    {
        abort_unless(app()->environment('local'), 404);

        $datos = $request->validate([
            'siscod' => 'required|string|max:50|unique:sistemas_cliente,siscod',
            'sisnom' => 'required|string|max:150',
        ]);

        $plainKey = Str::random(64);

        SistemaCliente::create([
            'siscod'    => strtoupper(trim($datos['siscod'])),
            'sisnom'    => trim($datos['sisnom']),
            'sisapikey' => hash('sha256', $plainKey),
            'sisest'    => true,
        ]);

        return redirect()
            ->route('panel.index')
            ->with('success', "Sistema '{$datos['siscod']}' creado correctamente.")
            ->with('generated_apikey', $plainKey);
    }

    public function storeEmisor(Request $request): RedirectResponse
    {
        abort_unless(app()->environment('local'), 404);

        $datos = $request->validate([
            'sisid'          => 'nullable|integer|exists:sistemas_cliente,sisid',
            'eminit'         => 'required|string|max:20',
            'emirazsoc'      => 'required|string|max:255',
            'emimun'         => 'required|string|max:100',
            'emidir'         => 'required|string|max:255',
            'emitel'         => 'nullable|string|max:30',
            'emisis'         => 'required|string|max:50',
            'emisuc'         => 'required|integer|min:0',
            'emipdv'         => 'required|integer|min:0',
            'emitoken'       => 'required|string',
            'emimod'         => 'required|in:1,2',
            'emiamb'         => 'required|in:1,2',
            'secuencia_init' => 'nullable|integer|min:0',
        ]);

        $suc = (int) $datos['emisuc'];
        $pdv = (int) $datos['emipdv'];
        $secuenciaInit = (int) ($datos['secuencia_init'] ?? 0);

        DB::transaction(function () use ($datos, $suc, $pdv, $secuenciaInit) {
            $emisor = Emisor::updateOrCreate(
                ['eminit' => trim($datos['eminit'])],
                [
                    'sisid'     => $datos['sisid'] ?: null,
                    'emirazsoc' => trim($datos['emirazsoc']),
                    'emimun'    => trim($datos['emimun']),
                    'emidir'    => trim($datos['emidir']),
                    'emitel'    => $datos['emitel'] ? trim($datos['emitel']) : null,
                    'emisis'    => trim($datos['emisis']),
                    'emisuc'    => $suc,
                    'emipdv'    => $pdv,
                    'emitoken'  => trim($datos['emitoken']),
                    'emimod'    => (int) $datos['emimod'],
                    'emiamb'    => (int) $datos['emiamb'],
                    'emiest'    => true,
                ]
            );

            PuntoVenta::firstOrCreate(
                ['emiid' => $emisor->emiid, 'pvsuc' => $suc, 'pvpdv' => $pdv],
                ['pvest' => true]
            );

            Secuencia::firstOrCreate(
                ['emiid' => $emisor->emiid, 'secsuc' => $suc, 'secpdv' => $pdv, 'sectipodoc' => 1],
                ['secultimo' => $secuenciaInit]
            );
        });

        return redirect()
            ->route('panel.index')
            ->with('success', "Emisor NIT {$datos['eminit']} registrado/actualizado junto con su Punto de Venta ({$suc}, {$pdv}) y Secuencia.");
    }

    public function storePuntoVenta(Request $request): RedirectResponse
    {
        abort_unless(app()->environment('local'), 404);

        $datos = $request->validate([
            'emiid'     => 'required|integer|exists:emisores,emiid',
            'pvsuc'     => 'required|integer|min:0',
            'pvpdv'     => 'required|integer|min:0',
            'secultimo' => 'required|integer|min:0',
        ]);

        $existe = PuntoVenta::where('emiid', $datos['emiid'])
            ->where('pvsuc', $datos['pvsuc'])
            ->where('pvpdv', $datos['pvpdv'])
            ->exists();

        if ($existe) {
            return redirect()
                ->route('panel.index')
                ->withErrors(['pv' => "La sucursal {$datos['pvsuc']} / PDV {$datos['pvpdv']} ya está registrada para este emisor."]);
        }

        DB::transaction(function () use ($datos) {
            PuntoVenta::create([
                'emiid' => $datos['emiid'],
                'pvsuc' => (int) $datos['pvsuc'],
                'pvpdv' => (int) $datos['pvpdv'],
                'pvest' => true,
            ]);

            Secuencia::create([
                'emiid'      => $datos['emiid'],
                'secsuc'     => (int) $datos['pvsuc'],
                'secpdv'     => (int) $datos['pvpdv'],
                'sectipodoc' => 1,
                'secultimo'  => (int) $datos['secultimo'],
            ]);
        });

        return redirect()
            ->route('panel.index')
            ->with('success', "Caja / Punto de Venta (Sucursal {$datos['pvsuc']}, PDV {$datos['pvpdv']}) creada con secuencia inicial #{$datos['secultimo']}.");
    }

    public function vincularSistema(Request $request, Emisor $emisor): RedirectResponse
    {
        abort_unless(app()->environment('local'), 404);

        $datos = $request->validate([
            'sisid' => 'nullable|integer|exists:sistemas_cliente,sisid',
        ]);

        $emisor->update(['sisid' => $datos['sisid'] ?: null]);

        return redirect()
            ->route('panel.index')
            ->with('success', "Vinculación de emisor NIT {$emisor->eminit} actualizada.");
    }

    public function gestionarCuis(Request $request): RedirectResponse
    {
        abort_unless(app()->environment('local'), 404);

        $datos = $request->validate([
            'emiid'       => 'required|integer|exists:emisores,emiid',
            'sucursal'    => 'required|integer|min:0',
            'pdv'         => 'required|integer|min:0',
            'modo'        => 'required|in:manual,siat',
            'cuis_manual' => 'required_if:modo,manual|nullable|string|max:50',
            'vencimiento' => 'nullable|string|max:60',
        ]);

        $emisor = Emisor::findOrFail($datos['emiid']);
        $suc = (int) $datos['sucursal'];
        $pdv = (int) $datos['pdv'];

        if ($datos['modo'] === 'manual') {
            $cuisValor = strtoupper(trim($datos['cuis_manual']));
            $vencimiento = !empty(trim($datos['vencimiento'] ?? ''))
                ? Carbon::parse(trim($datos['vencimiento']))
                : now()->addYear();

            DB::transaction(function () use ($emisor, $suc, $pdv, $cuisValor, $vencimiento) {
                SiatCodigo::where('emiid', $emisor->emiid)
                    ->whereIn('scotipo', ['CUIS', 'cuis'])
                    ->where('scoamb', $emisor->emiamb)
                    ->where('scosuc', $suc)
                    ->where('scopdv', $pdv)
                    ->where('scoest', true)
                    ->update(['scoest' => false]);

                SiatCodigo::create([
                    'emiid'      => $emisor->emiid,
                    'scotipo'    => 'CUIS',
                    'scovalor'   => $cuisValor,
                    'scocodctrl' => null,
                    'scoamb'     => $emisor->emiamb,
                    'scosuc'     => $suc,
                    'scopdv'     => $pdv,
                    'scoemi'     => now(),
                    'scoven'     => $vencimiento,
                    'scoest'     => true,
                ]);
            });

            return redirect()
                ->route('panel.index')
                ->with('success', "CUIS manual '{$cuisValor}' registrado para NIT {$emisor->eminit} (Suc {$suc}, PDV {$pdv}) con vencimiento {$vencimiento->format('Y-m-d H:i:s')}.");
        }

        // Solicitar CUIS al SIAT vía SOAP
        try {
            $siat = new SiatService($emisor, $suc, $pdv);
            $res = $siat->getCuis();

            if (!($res['success'] ?? false)) {
                $detalles = $res['detalles'] ?? null;
                $codigoExistente = is_object($detalles) ? ($detalles->codigo ?? null) : null;
                $fechaVigencia = is_object($detalles) ? ($detalles->fechaVigencia ?? null) : null;

                // Si el SIAT informa que ya existe un CUIS vigente (ej. código 980) y devuelve código + fechaVigencia, lo persistimos
                if (!empty($codigoExistente)) {
                    $venc = !empty($fechaVigencia) ? Carbon::parse($fechaVigencia) : now()->addYear();

                    DB::transaction(function () use ($emisor, $suc, $pdv, $codigoExistente, $venc) {
                        SiatCodigo::where('emiid', $emisor->emiid)
                            ->whereIn('scotipo', ['CUIS', 'cuis'])
                            ->where('scoamb', $emisor->emiamb)
                            ->where('scosuc', $suc)
                            ->where('scopdv', $pdv)
                            ->where('scoest', true)
                            ->update(['scoest' => false]);

                        SiatCodigo::create([
                            'emiid'      => $emisor->emiid,
                            'scotipo'    => 'CUIS',
                            'scovalor'   => strtoupper(trim((string) $codigoExistente)),
                            'scocodctrl' => null,
                            'scoamb'     => $emisor->emiamb,
                            'scosuc'     => $suc,
                            'scopdv'     => $pdv,
                            'scoemi'     => now(),
                            'scoven'     => $venc,
                            'scoest'     => true,
                        ]);
                    });

                    return redirect()
                        ->route('panel.index')
                        ->with('success', "CUIS vigente recuperado desde SIAT: {$codigoExistente} (vigente hasta {$venc->format('Y-m-d H:i:s')}).");
                }

                return redirect()
                    ->route('panel.index')
                    ->withErrors(['siat' => 'El SIAT no devolvió un CUIS nuevo. Puedes ingresarlo de forma manual sin necesidad de fecha de vencimiento.']);
            }

            $vencimiento = now()->addMonths(11);
            DB::transaction(function () use ($emisor, $suc, $pdv, $res, $vencimiento) {
                SiatCodigo::where('emiid', $emisor->emiid)
                    ->whereIn('scotipo', ['CUIS', 'cuis'])
                    ->where('scoamb', $emisor->emiamb)
                    ->where('scosuc', $suc)
                    ->where('scopdv', $pdv)
                    ->where('scoest', true)
                    ->update(['scoest' => false]);

                SiatCodigo::create([
                    'emiid'      => $emisor->emiid,
                    'scotipo'    => 'CUIS',
                    'scovalor'   => $res['codigo'],
                    'scocodctrl' => null,
                    'scoamb'     => $emisor->emiamb,
                    'scosuc'     => $suc,
                    'scopdv'     => $pdv,
                    'scoemi'     => now(),
                    'scoven'     => $vencimiento,
                    'scoest'     => true,
                ]);
            });

            return redirect()
                ->route('panel.index')
                ->with('success', "CUIS obtenido desde SIAT: {$res['codigo']} (NIT {$emisor->eminit}, Suc {$suc}, PDV {$pdv}).");
        } catch (Throwable $e) {
            return redirect()
                ->route('panel.index')
                ->withErrors(['siat' => 'Error al solicitar CUIS al SIAT: ' . $e->getMessage()]);
        }
    }

    public function generarCufd(Request $request): RedirectResponse
    {
        abort_unless(app()->environment('local'), 404);

        $datos = $request->validate([
            'emiid'    => 'required|integer|exists:emisores,emiid',
            'sucursal' => 'required|integer|min:0',
            'pdv'      => 'required|integer|min:0',
        ]);

        $emisor = Emisor::findOrFail($datos['emiid']);
        $suc = (int) $datos['sucursal'];
        $pdv = (int) $datos['pdv'];

        try {
            $siat = new SiatService($emisor, $suc, $pdv);
            $cufd = $siat->getActiveCufd();

            if (!$cufd) {
                return redirect()
                    ->route('panel.index')
                    ->withErrors(['siat' => 'No se pudo generar el CUFD. Asegúrate de tener primero un CUIS vigente para esta sucursal y PDV.']);
            }

            return redirect()
                ->route('panel.index')
                ->with('success', "CUFD vigente generado para NIT {$emisor->eminit} (Suc {$suc}, PDV {$pdv}) hasta {$cufd->scoven}.");
        } catch (Throwable $e) {
            return redirect()
                ->route('panel.index')
                ->withErrors(['siat' => 'Error al generar CUFD: ' . $e->getMessage()]);
        }
    }

    public function sincronizarCatalogos(): RedirectResponse
    {
        abort_unless(app()->environment('local'), 404);

        try {
            Artisan::call('siat:sincronizar-catalogos');
            $salida = trim(Artisan::output());

            return redirect()
                ->route('panel.index')
                ->with('success', 'Sincronización de catálogos y hora SIAT completada.')
                ->with('artisan_output', $salida);
        } catch (Throwable $e) {
            return redirect()
                ->route('panel.index')
                ->withErrors(['siat' => 'Error al sincronizar catálogos: ' . $e->getMessage()]);
        }
    }

    public function regenerarApiKey(SistemaCliente $sistema): RedirectResponse {
        abort_unless(app()->environment('local'), 404);

        $plainKey = Str::random(64);

        $sistema->update([
            'sisapikey' => hash('sha256', $plainKey),
            'sisest' => true,
        ]);

        return redirect()
            ->route('panel.index')
            ->route('success', "Nueva api")
            ->with('generated_apikey', $plainKey);
    }
    public function updateSistema(Request $request, SistemaCliente $sistema): RedirectResponse
    {
        abort_unless(app()->environment('local'), 404);

        $datos = $request->validate([
            'siscod' => 'required|string|max:50|unique:sistemas_cliente,siscod,' . $sistema->sisid . ',sisid',
            'sisnom' => 'required|string|max:150',
            'sisest' => 'nullable|boolean',
        ]);

        $sistema->update([
            'siscod' => strtoupper(trim($datos['siscod'])),
            'sisnom' => trim($datos['sisnom']),
            'sisest' => $request->boolean('sisest', true),
        ]);

        return redirect()
            ->route('panel.index')
            ->with('success', "Sistema '{$sistema->siscod}' actualizado correctamente.");
    }

    public function destroySistema(SistemaCliente $sistema): RedirectResponse
    {
        abort_unless(app()->environment('local'), 404);

        $codigo = $sistema->siscod;
        $sistema->delete();

        return redirect()
            ->route('panel.index')
            ->with('success', "Sistema '{$codigo}' eliminado.");
    }
}