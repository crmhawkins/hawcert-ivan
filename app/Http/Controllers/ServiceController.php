<?php

namespace App\Http\Controllers;

use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ServiceController extends Controller
{
    public function index()
    {
        $grouped = Service::with('certificates')
            ->orderBy('name')
            ->get()
            ->groupBy(function ($service) {
                // Agrupa por el dominio del endpoint si existe, o por nombre completo
                if ($service->endpoint) {
                    preg_match('/https?:\/\/(?:www\.)?([^\/\s]+)/', $service->endpoint, $matches);
                    return $matches[1] ?? $service->name;
                }
                return $service->name;
            })
            ->sortKeys();

        return view('services.index', compact('grouped'));
    }

    public function create()
    {
        return view('services.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'         => 'required|string|max:255|unique:services',
            'slug'         => 'nullable|string|max:255|unique:services',
            'description'  => 'nullable|string',
            'endpoint'     => 'nullable|url',
            'is_active'    => 'boolean',
            'service_type' => 'required|in:web,ssh',
            'ssh_host'     => 'nullable|string|max:255|required_if:service_type,ssh',
            'ssh_port'     => 'nullable|integer|min:1|max:65535',
            'ssh_user'     => 'nullable|string|max:100',
            'api_secret'   => 'nullable|string|max:64',
        ]);

        $validated['slug'] = $validated['slug'] ?? Str::slug($validated['name']);
        $validated['is_active'] = $validated['is_active'] ?? true;

        $data = array_merge($validated, [
            'service_type' => $validated['service_type'] ?? 'web',
            'ssh_host'     => $validated['ssh_host'] ?? null,
            'ssh_port'     => $validated['ssh_port'] ?? 22,
            'ssh_user'     => $validated['ssh_user'] ?? null,
            'api_secret'   => $validated['api_secret'] ?? null,
        ]);

        if (($validated['service_type'] ?? 'web') === 'ssh' && empty($data['api_secret'])) {
            $data['api_secret'] = bin2hex(random_bytes(32)); // 64-char hex secret
        }

        Service::create($data);

        return redirect()->route('services.index')
            ->with('success', 'Servicio creado exitosamente.');
    }

    public function show(Service $service)
    {
        $service->load('certificates.user');
        return view('services.show', compact('service'));
    }

    public function edit(Service $service)
    {
        return view('services.edit', compact('service'));
    }

    public function update(Request $request, Service $service)
    {
        $validated = $request->validate([
            'name'         => 'required|string|max:255|unique:services,name,' . $service->id,
            'slug'         => 'nullable|string|max:255|unique:services,slug,' . $service->id,
            'description'  => 'nullable|string',
            'endpoint'     => 'nullable|url',
            'is_active'    => 'boolean',
            'service_type' => 'required|in:web,ssh',
            'ssh_host'     => 'nullable|string|max:255|required_if:service_type,ssh',
            'ssh_port'     => 'nullable|integer|min:1|max:65535',
            'ssh_user'     => 'nullable|string|max:100',
            'api_secret'   => 'nullable|string|max:64',
        ]);

        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $data = array_merge($validated, [
            'service_type' => $validated['service_type'] ?? 'web',
            'ssh_host'     => $validated['ssh_host'] ?? null,
            'ssh_port'     => $validated['ssh_port'] ?? 22,
            'ssh_user'     => $validated['ssh_user'] ?? null,
            'api_secret'   => $validated['api_secret'] ?? null,
        ]);

        $service->update($data);

        return redirect()->route('services.index')
            ->with('success', 'Servicio actualizado exitosamente.');
    }

    public function destroy(Service $service)
    {
        $service->delete();
        return redirect()->route('services.index')
            ->with('success', 'Servicio eliminado exitosamente.');
    }
}
