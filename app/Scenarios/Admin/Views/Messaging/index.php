<section class="content">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h3 mb-1">Mensajes</h1>
            <p class="text-secondary mb-0">Envía avisos internos a un usuario o a todos los usuarios activos.</p>
        </div>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="status">
            <?= htmlspecialchars($flash['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if ($canWrite): ?>
        <div class="card card-primary card-outline mb-4">
            <div class="card-header"><h2 class="card-title">Redactar mensaje</h2></div>
            <form method="post" action="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/mensajes">
                <div class="card-body">
                    <input type="hidden" name="_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <div class="mb-3">
                        <label for="recipient" class="form-label">Destinatario</label>
                        <select id="recipient" name="recipient" class="form-select" required>
                            <option value="all">Todos los usuarios activos</option>
                            <?php foreach ($users as $recipient): ?>
                                <option value="<?= (int) $recipient['id'] ?>">
                                    <?= htmlspecialchars($recipient['username'] . ' · ' . $recipient['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="subject" class="form-label">Asunto</label>
                        <input id="subject" name="subject" class="form-control" maxlength="160" required>
                    </div>
                    <div class="mb-0">
                        <label for="message-body" class="form-label">Mensaje</label>
                        <textarea id="message-body" name="body" class="form-control" rows="5" maxlength="5000" required></textarea>
                    </div>
                </div>
                <div class="card-footer">
                    <button class="btn btn-primary" type="submit"><i class="bi bi-send me-1" aria-hidden="true"></i>Enviar mensaje</button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><h2 class="card-title">Mensajes enviados</h2></div>
        <div class="card-body p-0">
            <?php if ($sentMessages === []): ?>
                <p class="text-secondary p-3 mb-0">Todavía no has enviado mensajes.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped align-middle mb-0">
                        <thead><tr><th>Asunto</th><th>Enviado</th><th>Destinatarios</th><th>Leídos</th></tr></thead>
                        <tbody>
                        <?php foreach ($sentMessages as $message): ?>
                            <tr>
                                <td><?= htmlspecialchars($message['subject'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($message['created_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                <td><?= (int) $message['recipient_count'] ?></td>
                                <td><?= (int) $message['read_count'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>
