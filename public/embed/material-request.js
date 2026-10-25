(function (global) {
    'use strict';

    const loaderScript = document.currentScript;

    function baseUrl(script) {
        if (script && script.src) {
            return new URL(script.src).origin;
        }
        return window.location.origin;
    }

    function mount(options, script) {
        const opts = options || {};
        const target = typeof opts.target === 'string'
            ? document.querySelector(opts.target)
            : (opts.target || document.getElementById('lia-mr-embed'));
        if (!target) {
            throw new Error('LIA material request: embed target not found.');
        }

        const src = new URL('/request-materials', opts.baseUrl || baseUrl(script));
        src.searchParams.set('type', opts.type || 'client');
        if (opts.prefill) {
            src.searchParams.set('prefill', opts.prefill);
        }
        if (opts.height) {
            src.searchParams.set('height', String(opts.height));
        }

        const iframe = document.createElement('iframe');
        iframe.src = src.toString();
        iframe.title = opts.title || 'Material / Equipment Request';
        iframe.style.width = '100%';
        iframe.style.minHeight = (opts.height || 1600) + 'px';
        iframe.style.border = '0';
        iframe.allow = 'camera';
        iframe.loading = 'lazy';
        target.innerHTML = '';
        target.appendChild(iframe);

        return iframe;
    }

    global.LIA = global.LIA || {};
    global.LIA.materialRequest = function (options) {
        return mount(options, loaderScript);
    };

    const current = document.currentScript;
    if (current && current.dataset.autoMount !== 'false' && current.dataset.target) {
        mount({
            target: current.dataset.target,
            type: current.dataset.type || 'client',
            prefill: current.dataset.prefill || null,
            height: current.dataset.height || 1600,
            baseUrl: current.dataset.baseUrl || null,
        }, current);
    }
})(window);
