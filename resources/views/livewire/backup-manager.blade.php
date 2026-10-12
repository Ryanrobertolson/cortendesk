<div>
    @if ($notice)
        <div class="alert alert-{{ $notice['tone'] }} py-2 mb-3" style="white-space: pre-line;">{{ $notice['text'] }}</div>
    @endif
    @if ($saved)
        <div class="alert alert-success py-2 mb-3">Backup settings saved.</div>
    @endif

    <div class="row">
        <div class="col-lg-7">
            <div class="card">
                <div class="rd-toolbar">
                    <div>
                        <h4 class="header-title">Backups</h4>
                        <p class="rd-card-sub mb-0">
                            Each archive holds the whole database, every setting, the app key
                            @if ($includesServerFiles) and the ID server's key pair and peer database @endif
                            &mdash; everything needed to rebuild this install. Treat it like a password.
                        </p>
                    </div>
                    <div class="rd-toolbar-actions">
                        <button type="button" class="btn btn-primary" wire:click="backupNow" wire:loading.attr="disabled" wire:target="backupNow" @disabled(! $canManage)>
                            <span wire:loading.remove wire:target="backupNow"><i class="ri-archive-line"></i>Back up now</span>
                            <span wire:loading wire:target="backupNow"><i class="ri-loader-4-line"></i>Backing up…</span>
                        </button>
                    </div>
                </div>

                @if ($directoryError)
                    <div class="alert alert-danger py-2 mx-3">{{ $directoryError }}</div>
                @endif

                {{-- Desktop table (md and up) --}}
                <div class="table-responsive d-none d-md-block">
                    <table class="table table-hover table-centered mb-0">
                        <thead>
                        <tr>
                            <th>Taken</th>
                            <th>Size</th>
                            <th>Version</th>
                            <th>Trigger</th>
                            <th class="text-end">Action</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse ($backups as $backup)
                            <tr wire:key="bk-{{ $backup['name'] }}">
                                <td>
                                    <span class="fw-semibold">{{ $backup['created_at']->format('Y-m-d H:i') }}</span>
                                    <span class="d-block rd-mono fs-13 text-muted">{{ $backup['name'] }}</span>
                                </td>
                                <td>{{ \App\Services\Backups\BackupService::humanSize($backup['size']) }}</td>
                                <td class="rd-mono">{{ $backup['manifest']['app_version'] ?? '—' }}</td>
                                <td><span class="badge bg-secondary-subtle text-secondary">{{ $backup['manifest']['trigger'] ?? 'unknown' }}</span></td>
                                <td class="text-end text-nowrap">
                                    @if ($canManage)
                                        <a class="btn btn-sm btn-light" href="{{ route('settings.backups.download', $backup['name']) }}" title="Download"><i class="ri-download-2-line"></i></a>
                                        <button type="button" class="btn btn-sm btn-light text-warning" title="Restore"
                                                wire:click="restore('{{ $backup['name'] }}')" wire:loading.attr="disabled"
                                                wire:confirm="Restore {{ $backup['name'] }}?\n\nThis OVERWRITES every setting, user, device, address book and log in this install with what the backup holds. Anything changed since it was taken is lost. There is no undo.">
                                            <i class="ri-history-line"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-light text-danger" title="Delete"
                                                wire:click="delete('{{ $backup['name'] }}')"
                                                wire:confirm="Delete {{ $backup['name'] }}?">
                                            <i class="ri-delete-bin-6-line"></i>
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-4">No backups yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- Mobile cards --}}
                <div class="d-md-none rd-cardlist">
                    @forelse ($backups as $backup)
                        <div class="rd-mini" wire:key="mbk-{{ $backup['name'] }}">
                            <div class="rd-mini-head">
                                <div class="min-width-0">
                                    <span class="rd-mini-title">{{ $backup['created_at']->format('Y-m-d H:i') }}</span>
                                    <span class="rd-mini-sub">{{ \App\Services\Backups\BackupService::humanSize($backup['size']) }} · v{{ $backup['manifest']['app_version'] ?? '?' }} · {{ $backup['manifest']['trigger'] ?? 'unknown' }}</span>
                                </div>
                            </div>
                            @if ($canManage)
                                <div class="d-flex gap-2 mt-2">
                                    <a class="btn btn-sm btn-light" href="{{ route('settings.backups.download', $backup['name']) }}"><i class="ri-download-2-line me-1"></i>Download</a>
                                    <button type="button" class="btn btn-sm btn-light text-warning"
                                            wire:click="restore('{{ $backup['name'] }}')"
                                            wire:confirm="Restore {{ $backup['name'] }}?\n\nThis OVERWRITES every setting, user, device, address book and log in this install with what the backup holds. Anything changed since it was taken is lost. There is no undo.">
                                        <i class="ri-history-line me-1"></i>Restore
                                    </button>
                                    <button type="button" class="btn btn-sm btn-light text-danger"
                                            wire:click="delete('{{ $backup['name'] }}')"
                                            wire:confirm="Delete {{ $backup['name'] }}?">
                                        <i class="ri-delete-bin-6-line"></i>
                                    </button>
                                </div>
                            @endif
                        </div>
                    @empty
                        <p class="text-muted text-center py-4 mb-0">No backups yet.</p>
                    @endforelse
                </div>

                <div class="card-body border-top">
                    @if ($lastResult)
                        <p class="fs-13 mb-2 {{ ($lastResult['ok'] ?? false) ? 'text-muted' : 'text-danger' }}">
                            <i class="{{ ($lastResult['ok'] ?? false) ? 'ri-checkbox-circle-line' : 'ri-error-warning-line' }} me-1"></i>
                            Last {{ $lastResult['trigger'] ?? '' }} run{{ $lastResult['at'] ? ' '.$lastResult['at']->diffForHumans() : '' }}: {{ $lastResult['message'] ?? '' }}
                        </p>
                    @endif
                    <p class="fs-13 text-muted mb-0">Stored in <span class="rd-mono">{{ $directory }}</span>.</p>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Restore from a file</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted fs-13">Upload an archive from another install or an off-site copy. Encrypted values are re-keyed to this install, so a backup from a different app key restores cleanly.</p>
                    <div class="alert alert-warning py-2 fs-13">
                        <i class="ri-alert-line me-1"></i><strong>Restoring overwrites all your settings</strong>, users, devices, address books and logs with the backup's contents. Anything changed since the backup was taken is lost. Make a backup first if you may want to come back.
                    </div>
                    <form wire:submit="restoreUpload">
                        <div class="mb-3">
                            <input type="file" class="form-control @error('upload') is-invalid @enderror" wire:model="upload" accept=".tar" @disabled(! $canManage)>
                            @error('upload') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            <div wire:loading wire:target="upload" class="form-text">Uploading…</div>
                        </div>
                        <button type="submit" class="btn btn-warning" wire:loading.attr="disabled"
                                wire:confirm="Restore from the uploaded file?\n\nThis OVERWRITES every setting, user, device, address book and log in this install. There is no undo."
                                @disabled(! $canManage || ! $upload)>
                            <i class="ri-history-line me-1"></i>Restore from file
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <form wire:submit="save">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Schedule</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="backupEnabled" wire:model="enabled" @disabled(! $canManage)>
                                <label class="form-check-label" for="backupEnabled">Automatic backups</label>
                            </div>
                            <div class="form-text">Runs every day at 03:30 server time. On by default.</div>
                        </div>
                        <div class="row g-3">
                            @foreach (['Keep daily' => 'keepDaily', 'Keep weekly' => 'keepWeekly', 'Keep monthly' => 'keepMonthly'] as $label => $field)
                                <div class="col-4">
                                    <label class="form-label" for="{{ $field }}">{{ $label }}</label>
                                    <input type="number" id="{{ $field }}" class="form-control @error($field) is-invalid @enderror" wire:model="{{ $field }}" min="0" max="{{ \App\Services\Backups\BackupService::MAX_KEEP }}" @disabled(! $canManage)>
                                    @error($field) <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                            @endforeach
                        </div>
                        <div class="form-text mt-2">All three apply at once: the newest {{ $keepDaily }} backups, the first backup of each of the last {{ $keepWeekly }} weeks, and the first of each of the last {{ $keepMonthly }} months. Everything else is deleted after each run. 0 turns a tier off.</div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Off-site copy</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label" for="backupTarget">Copy each backup to</label>
                            <select id="backupTarget" class="form-select @error('target') is-invalid @enderror" wire:model.live="target" @disabled(! $canManage)>
                                @foreach ($targets as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('target') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            <div class="form-text">Mirrors the list on this server: after each run the new backup goes up and anything pruned or deleted here is removed there. The local copy stays either way.</div>
                        </div>

                        @if ($target === 'ftp')
                            <div class="row g-3">
                                <div class="col-8">
                                    <label class="form-label">Host</label>
                                    <input type="text" class="form-control @error('ftpHost') is-invalid @enderror" wire:model="ftpHost" placeholder="ftp.example.com" @disabled(! $canManage)>
                                    @error('ftpHost') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-4">
                                    <label class="form-label">Port</label>
                                    <input type="number" class="form-control @error('ftpPort') is-invalid @enderror" wire:model="ftpPort" min="1" max="65535" @disabled(! $canManage)>
                                    @error('ftpPort') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label">Username</label>
                                    <input type="text" class="form-control" wire:model="ftpUsername" autocomplete="off" @disabled(! $canManage)>
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label">Password</label>
                                    <input type="password" class="form-control" wire:model="ftpPassword" autocomplete="new-password"
                                           placeholder="{{ $ftpPasswordSet ? '•••••••• (stored, blank keeps it)' : '' }}" @disabled(! $canManage)>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Directory</label>
                                    <input type="text" class="form-control" wire:model="ftpPath" placeholder="backups/cortendesk" @disabled(! $canManage)>
                                    <div class="form-text">Created if missing. Relative to the sign-in directory.</div>
                                </div>
                                <div class="col-12">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="ftpTls" wire:model="ftpTls" @disabled(! $canManage)>
                                        <label class="form-check-label" for="ftpTls">Use TLS (explicit FTPS)</label>
                                    </div>
                                    <div class="form-text">Plain FTP sends the password in clear. SFTP is not supported.</div>
                                </div>
                            </div>
                        @elseif ($target === 's3')
                            <div class="row g-3">
                                <div class="col-sm-7">
                                    <label class="form-label">Bucket</label>
                                    <input type="text" class="form-control @error('s3Bucket') is-invalid @enderror" wire:model="s3Bucket" @disabled(! $canManage)>
                                    @error('s3Bucket') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-sm-5">
                                    <label class="form-label">Region</label>
                                    <input type="text" class="form-control" wire:model="s3Region" placeholder="us-east-1" @disabled(! $canManage)>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Endpoint</label>
                                    <input type="text" class="form-control @error('s3Endpoint') is-invalid @enderror" wire:model="s3Endpoint" placeholder="https://s3.us-east-1.amazonaws.com" @disabled(! $canManage)>
                                    @error('s3Endpoint') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    <div class="form-text">Leave empty for AWS. MinIO, Backblaze B2, Wasabi, R2 and the like need their own.</div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Folder (prefix)</label>
                                    <input type="text" class="form-control" wire:model="s3Prefix" placeholder="cortendesk" @disabled(! $canManage)>
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label">Access key ID</label>
                                    <input type="text" class="form-control rd-mono" wire:model="s3AccessKey" autocomplete="off" @disabled(! $canManage)>
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label">Secret access key</label>
                                    <input type="password" class="form-control" wire:model="s3SecretKey" autocomplete="new-password"
                                           placeholder="{{ $s3SecretKeySet ? '•••••••• (stored, blank keeps it)' : '' }}" @disabled(! $canManage)>
                                </div>
                                <div class="col-12">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="s3PathStyle" wire:model="s3PathStyle" @disabled(! $canManage)>
                                        <label class="form-check-label" for="s3PathStyle">Path-style addressing</label>
                                    </div>
                                    <div class="form-text">Bucket in the path instead of the host name. Needed by MinIO and most self-hosted servers.</div>
                                </div>
                            </div>
                        @endif

                        <div class="d-flex gap-2 mt-3">
                            <button type="submit" class="btn btn-primary" @disabled(! $canManage)><i class="ri-save-line me-1"></i>Save</button>
                            @if ($target !== 'none')
                                <button type="button" class="btn btn-light" wire:click="testTarget" wire:loading.attr="disabled" wire:target="testTarget" @disabled(! $canManage)>
                                    <i class="ri-plug-line me-1"></i>Test connection
                                </button>
                            @endif
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
