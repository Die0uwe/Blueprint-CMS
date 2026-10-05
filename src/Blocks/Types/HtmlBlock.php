<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
declare(strict_types=1);
namespace CommunityFusion\Blocks\Types;
use CommunityFusion\Blocks\AbstractBlock;

/**
 * Vrij HTML blok — alleen voor admins bedoeld.
 *
 * Twee weergaven:
 *  - inline : de HTML wordt rechtstreeks in de pagina geplaatst (handig voor een
 *             los stukje markup, zoals een knop of een embed).
 *  - iframe : de HTML draait afgeschermd in een sandboxed iframe (srcdoc). Nodig voor
 *             een volledige pagina (<!DOCTYPE>, <html>, <body>, eigen <style> met
 *             selectors als `body`, `h1`, `.card`): inline zou die CSS over de hele
 *             site heen leggen, en de site-CSS over de blok-inhoud.
 *  - auto   : (standaard) iframe zodra de inhoud een volledig document lijkt, anders inline.
 */
final class HtmlBlock extends AbstractBlock
{
    private const MODES = ['auto', 'inline', 'iframe'];

    public function getSlug(): string { return 'html'; }
    public function getName(): string { return 'HTML Blok'; }

    public function getConfigSchema(): array
    {
        return [
            'content' => ['type' => 'code', 'label' => 'HTML inhoud', 'required' => true],
            'mode'    => [
                'type'    => 'select',
                'label'   => 'Weergave',
                'options' => self::MODES,
                'default' => 'auto',
                'help'    => 'auto = een volledige pagina (met <html>/<body>) wordt afgeschermd in een iframe, losse HTML komt gewoon in de site. '
                           . 'iframe = altijd afschermen. inline = altijd rechtstreeks in de pagina. '
                           . 'In een iframe werkt localStorage/cookies van de site niet.',
            ],
            'style'   => [
                'type'    => 'select',
                'label'   => 'Stijl',
                'options' => ['own', 'theme'],
                'default' => 'own',
                'help'    => 'own = de eigen CSS van de HTML blijft zoals hij is. theme = kleuren, lettertype en links van het actieve thema worden '
                           . 'over de eigen CSS heen gelegd (werkt alleen in de iframe-weergave).',
            ],
            'height'  => [
                'type'    => 'integer',
                'label'   => 'Hoogte van het iframe in px (0 = past zich vanzelf aan)',
                'default' => 0,
                'min'     => 0,
                'max'     => 4000,
            ],
        ];
    }

    public function render(array $config, array $context = []): string
    {
        $content = (string) ($config['content'] ?? '');
        if (trim($content) === '') {
            return '';
        }

        $mode = (string) ($config['mode'] ?? 'auto');
        if (!in_array($mode, self::MODES, true)) {
            $mode = 'auto';
        }

        if ($mode === 'auto') {
            $mode = self::looksLikeDocument($content) ? 'iframe' : 'inline';
        }

        if ($mode === 'inline') {
            // Geen escaping — admin-only, vertrouwde invoer
            return $content;
        }

        $height = max(0, min(4000, (int) ($config['height'] ?? 0)));
        return self::frame($content, $height, ($config['style'] ?? 'own') === 'theme');
    }

    /** Volledig HTML-document (doctype of <html>/<head>/<body>-tag) i.p.v. een los stukje markup. */
    public static function looksLikeDocument(string $html): bool
    {
        return preg_match('/<(?:!doctype|html|head|body)\b/i', $html) === 1;
    }

    /**
     * Afgeschermd iframe (srcdoc, sandbox zonder allow-same-origin). Ook gebruikt door
     * HTML-pagina's (Pages, template 'html').
     */
    public static function frame(string $content, int $height = 0, bool $themed = false): string
    {
        $id   = bin2hex(random_bytes(4));
        $auto = $height === 0;

        // Alles wat we toevoegen staat NA de inhoud van de admin: een <!DOCTYPE> moet
        // het eerste in het document blijven (anders quirks mode). Browsers verwerken
        // een <base> en <script> na </html> gewoon als onderdeel van de pagina.
        $doc = $content . "\n" . '<base target="_blank">';
        if ($auto) {
            // Het iframe heeft geen allow-same-origin, dus de pagina kan de inhoudshoogte
            // niet zelf uitlezen: het frame meldt die via postMessage.
            $doc .= '<script>(function(){var id="' . $id . '";function s(){try{var b=document.body,r=b.getBoundingClientRect(),'
                  . 'h=Math.ceil(r.bottom+(window.pageYOffset||0)+(parseFloat(getComputedStyle(b).marginBottom)||0));'
                  . 'parent.postMessage({cfFrame:id,h:h},"*")}catch(e){}}'
                  . 'addEventListener("load",s);addEventListener("resize",s);setTimeout(s,300);'
                  . 'if(window.ResizeObserver){new ResizeObserver(s).observe(document.body)}})();</script>';
        }

        if ($themed) {
            // Ontvanger: de site stuurt (via postMessage) CSS met de actuele themakleuren; die komt als
            // laatste stylesheet in het document en wint met !important van de eigen CSS.
            $doc .= '<script>addEventListener("message",function(e){var d=e.data;if(e.source!==parent||!d||d.cfTheme!=="' . $id . '"'
                  . '||typeof d.css!=="string")return;var s=document.getElementById("cf-theme");if(!s){s=document.createElement("style");'
                  . 's.id="cf-theme";document.head.appendChild(s)}s.textContent=d.css;});</script>';
        }

        $srcdoc = htmlspecialchars($doc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $style  = 'display:block;width:100%;border:0;border-radius:8px;height:' . ($auto ? 600 : $height) . 'px;';

        $frame = '<iframe id="cf-html-' . $id . '" class="cf-html-frame" title="Ingesloten HTML" loading="lazy" '
               . 'sandbox="allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox" '
               . 'style="' . $style . '" srcdoc="' . $srcdoc . '"></iframe>';

        $themeScript = $themed ? self::themeSender($id) : '';

        if (!$auto) {
            return $frame . $themeScript;
        }

        $listener = '<script>addEventListener("message",function(e){var d=e.data,f=document.getElementById("cf-html-' . $id . '");'
                  . 'if(!d||d.cfFrame!=="' . $id . '"||!f||e.source!==f.contentWindow)return;'
                  . 'f.style.height=Math.min(Math.max(+d.h||0,80),4000)+"px"});</script>';

        return $frame . $listener . $themeScript;
    }

    /**
     * Stuurt de actuele themakleuren naar het iframe (bij laden, themawissel en kleurschema-wijziging).
     * De waarden komen uit de berekende stijl van de pagina, dus ze kloppen in elk thema.
     */
    private static function themeSender(string $id): string
    {
        return '<script>(function(){var f=document.getElementById("cf-html-' . $id . '");if(!f)return;'
             . 'function v(n,d){var x=getComputedStyle(document.documentElement).getPropertyValue(n).trim();return x||d}'
             . 'function css(){var bg=v("--bg","#0a0c14"),tx=v("--text","#e2e8f0"),sf=v("--surface","#111827"),bd=v("--border","#1e2940"),'
             . 'ac=v("--accent","#6c3df4"),lk=v("--link",ac),ff=getComputedStyle(document.body).fontFamily;'
             . 'return ":root{--bg:"+bg+";--text:"+tx+";--surface:"+sf+";--border:"+bd+";--accent:"+ac+";--link:"+lk+"}"'
             . '+"html,body{background:"+bg+"!important;color:"+tx+"!important;font-family:"+ff+"!important}"'
             . '+"div,section,article,aside,header,footer,main,nav,form,table,thead,tbody,tr,td,th,ul,ol,li,p,span,label,small,strong,em,h1,h2,h3,h4,h5,h6,blockquote,pre,code,details,summary{background-color:transparent!important;color:"+tx+"!important;border-color:"+bd+"!important}"'
             . '+"[class*=card],[class*=panel],[class*=box],[class*=tile],[class*=widget],[class*=modal]{background-color:"+sf+"!important;border-color:"+bd+"!important}"'
             . '+"a{color:"+lk+"!important}input,select,textarea{background:"+sf+"!important;color:"+tx+"!important;border-color:"+bd+"!important}"'
             . '+"button,.btn,[class*=button]{border-color:"+ac+"!important}"}'
             . 'function send(){try{f.contentWindow.postMessage({cfTheme:"' . $id . '",css:css()},"*")}catch(e){}}'
             . 'f.addEventListener("load",send);setTimeout(send,200);'
             . 'new MutationObserver(send).observe(document.documentElement,{attributes:true,attributeFilter:["data-theme","class","style"]});'
             . 'if(window.matchMedia)matchMedia("(prefers-color-scheme: dark)").addEventListener("change",send);'
             . '})();</script>';
    }

    public function getCacheTtl(): int { return 1800; }
}
