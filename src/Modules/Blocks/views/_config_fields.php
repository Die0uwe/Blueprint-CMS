<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
// Gedeelde veld-renderer voor het blok-aanmaak- en bewerkformulier.
//
// Verwacht:
//   $schema (array)  — BlockInterface::getConfigSchema()
//   $values (array)  — huidige waarden (leeg bij nieuw blok → 'default' uit schema)
//
// Ondersteunde veldtypes: string, integer, number, range (slider), boolean, select, textarea,
// code, richtext. 'textarea', 'code' en 'richtext' krijgen een data-editor-
// attribuut zodat de gedeelde editor (Golf 2) zich er automatisch aan kan hangen.
$h = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

foreach ($schema as $key => $field):
    $type  = (string) ($field['type'] ?? 'string');
    $label = (string) ($field['label'] ?? $key);
    $name  = 'config[' . $key . ']';
    $value = array_key_exists($key, $values) ? $values[$key] : ($field['default'] ?? '');
    $req   = !empty($field['required']);
    $id    = 'cfg_' . preg_replace('/[^a-z0-9_]/i', '_', (string) $key);
?>
<div class="cf-form-group">
  <?php if ($type === 'boolean'): ?>
    <input type="hidden" name="<?= $h($name) ?>" value="0">
    <label class="cf-label" for="<?= $h($id) ?>" style="display:flex;align-items:center;gap:.5rem;font-weight:400;">
      <input type="checkbox" id="<?= $h($id) ?>" name="<?= $h($name) ?>" value="1" style="width:auto;"
             <?= !empty($value) ? 'checked' : '' ?>>
      <?= $h($label) ?>
    </label>
  <?php else: ?>
    <label class="cf-label" for="<?= $h($id) ?>"><?= $h($label) ?><?= $req ? ' *' : '' ?></label>

    <?php if ($type === 'textarea' || $type === 'richtext'): ?>
      <textarea id="<?= $h($id) ?>" name="<?= $h($name) ?>" class="cf-textarea" rows="8"
                data-editor="<?= $type === 'richtext' ? 'richtext' : 'plain' ?>"
                <?= $req ? 'required' : '' ?>><?= $h($value) ?></textarea>

    <?php elseif ($type === 'code'): ?>
      <textarea id="<?= $h($id) ?>" name="<?= $h($name) ?>" class="cf-textarea" rows="12"
                data-editor="code" spellcheck="false"
                style="font-family:ui-monospace,Consolas,monospace;font-size:.85rem;"
                <?= $req ? 'required' : '' ?>><?= $h($value) ?></textarea>

    <?php elseif ($type === 'select'): ?>
      <select id="<?= $h($id) ?>" name="<?= $h($name) ?>" class="cf-select">
        <?php foreach ((array) ($field['options'] ?? []) as $opt): ?>
          <option value="<?= $h($opt) ?>" <?= (string) $opt === (string) $value ? 'selected' : '' ?>><?= $h($opt) ?></option>
        <?php endforeach; ?>
      </select>

    <?php elseif ($type === 'range'): ?>
      <div style="display:flex;align-items:center;gap:.75rem;">
        <input type="range" id="<?= $h($id) ?>" name="<?= $h($name) ?>" value="<?= $h($value) ?>"
               min="<?= $h($field['min'] ?? 0) ?>" max="<?= $h($field['max'] ?? 100) ?>" step="<?= $h($field['step'] ?? 1) ?>"
               style="flex:1;" oninput="this.nextElementSibling.textContent=this.value+'<?= $h($field['unit'] ?? '') ?>'">
        <output style="min-width:4.5rem;text-align:right;font-variant-numeric:tabular-nums;"><?= $h($value) ?><?= $h($field['unit'] ?? '') ?></output>
      </div>

    <?php elseif ($type === 'integer' || $type === 'number'): ?>
      <input type="number" id="<?= $h($id) ?>" name="<?= $h($name) ?>" class="cf-input"
             value="<?= $h($value) ?>" <?= $type === 'number' ? 'step="any"' : 'step="1"' ?>
             <?= isset($field['min']) ? 'min="' . $h($field['min']) . '"' : '' ?>
             <?= isset($field['max']) ? 'max="' . $h($field['max']) . '"' : '' ?>
             <?= $req ? 'required' : '' ?>>

    <?php else: ?>
      <input type="text" id="<?= $h($id) ?>" name="<?= $h($name) ?>" class="cf-input"
             value="<?= $h($value) ?>" <?= $req ? 'required' : '' ?>
             <?= !empty($field['pattern']) ? 'pattern="' . $h($field['pattern']) . '"' : '' ?>>
    <?php endif; ?>

    <?php if (!empty($field['help'])): ?>
      <small style="color:var(--muted);"><?= $h($field['help']) ?></small>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php endforeach; ?>
