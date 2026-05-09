@extends('layouts.admin')

@section('title')
PMCP — Plugins Minecraft
@endsection

@section('content')
<div class="row">
    <div class="col-xs-12">
        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title">Minecraft Plugins (PMCP)</h3>
                <div class="box-tools pull-right">
                    @php
                        $pmcpWebsite = '{website}';
                    @endphp
                    @if($pmcpWebsite !== '')
                        <a href="{{ $pmcpWebsite }}" class="btn btn-sm btn-default" target="_blank" rel="noopener">Site</a>
                    @endif
                </div>
            </div>
            <div class="box-body">
                @if('{is_target}' !== 'true')
                    <div class="callout callout-warning">
                        Version Blueprint du panel&nbsp;: <code>{target}</code> —
                        cible déclarée par l’extension dans <code>conf.yml</code> peut différer.
                    </div>
                @endif
                <p><strong>{name}</strong> — identifiant <code>{identifier}</code>, version <code>{version}</code>.</p>
                <p>Interface policies et catalogue : développement en cours.</p>
                <p class="text-muted">
                    Endpoint client santé&nbsp;:
                    <code>GET /api/client/extensions/{identifier}/health</code>
                </p>
            </div>
        </div>
    </div>
</div>
@endsection
