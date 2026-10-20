/**
 * Minimal dependency-free canvas charts for the dashboard and reports.
 * Uses CSS custom properties for colours so the theme stays consistent.
 */
(function () {
    'use strict';

    function cssVar(name, fallback) {
        var v = getComputedStyle(document.documentElement).getPropertyValue(name);
        return (v && v.trim()) || fallback;
    }

    function setupCanvas(canvas) {
        var dpr = window.devicePixelRatio || 1;
        var rect = canvas.getBoundingClientRect();
        var w = rect.width || canvas.clientWidth || 600;
        var h = rect.height || 260;
        canvas.width = Math.round(w * dpr);
        canvas.height = Math.round(h * dpr);
        var ctx = canvas.getContext('2d');
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        return { ctx: ctx, w: w, h: h };
    }

    function niceMax(value) {
        if (value <= 0) return 10;
        var pow = Math.pow(10, Math.floor(Math.log10(value)));
        var n = value / pow;
        var step = n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10;
        return step * pow;
    }

    function formatShort(v) {
        if (v >= 1000000) return (v / 1000000).toFixed(1) + 'M';
        if (v >= 1000) return (v / 1000).toFixed(v % 1000 === 0 ? 0 : 1) + 'k';
        return String(Math.round(v));
    }

    function drawLineChart(canvas, opts) {
        var data = opts.data || [];
        if (!data.length) return;
        var s = setupCanvas(canvas);
        var ctx = s.ctx, w = s.w, h = s.h;
        var padL = 46, padR = 14, padT = 16, padB = 30;
        var plotW = w - padL - padR;
        var plotH = h - padT - padB;

        var max = niceMax(Math.max.apply(null, data.map(function (d) { return d.value; })));
        var gridColor = cssVar('--line', '#e4e9f0');
        var muted = cssVar('--muted', '#6b7a8d');
        var accent = cssVar('--accent-500', '#12a488');

        ctx.clearRect(0, 0, w, h);
        ctx.font = '11px Inter, Segoe UI, sans-serif';
        ctx.textBaseline = 'middle';

        var steps = 4;
        ctx.strokeStyle = gridColor;
        ctx.fillStyle = muted;
        ctx.lineWidth = 1;
        for (var i = 0; i <= steps; i++) {
            var y = padT + (plotH / steps) * i;
            var val = max - (max / steps) * i;
            ctx.beginPath();
            ctx.moveTo(padL, y);
            ctx.lineTo(w - padR, y);
            ctx.stroke();
            ctx.textAlign = 'right';
            ctx.fillText(formatShort(val), padL - 8, y);
        }

        var stepX = data.length > 1 ? plotW / (data.length - 1) : plotW;
        var points = data.map(function (d, idx) {
            return {
                x: padL + stepX * idx,
                y: padT + plotH - (d.value / max) * plotH,
                label: d.label,
                value: d.value
            };
        });

        // Area gradient
        var grad = ctx.createLinearGradient(0, padT, 0, padT + plotH);
        grad.addColorStop(0, hexA(accent, 0.28));
        grad.addColorStop(1, hexA(accent, 0));
        ctx.beginPath();
        ctx.moveTo(points[0].x, padT + plotH);
        points.forEach(function (p) { ctx.lineTo(p.x, p.y); });
        ctx.lineTo(points[points.length - 1].x, padT + plotH);
        ctx.closePath();
        ctx.fillStyle = grad;
        ctx.fill();

        // Line
        ctx.beginPath();
        points.forEach(function (p, i) { i ? ctx.lineTo(p.x, p.y) : ctx.moveTo(p.x, p.y); });
        ctx.strokeStyle = accent;
        ctx.lineWidth = 2.5;
        ctx.lineJoin = 'round';
        ctx.stroke();

        // Dots + x labels
        ctx.textAlign = 'center';
        points.forEach(function (p, i) {
            ctx.beginPath();
            ctx.arc(p.x, p.y, 3.5, 0, Math.PI * 2);
            ctx.fillStyle = accent;
            ctx.fill();
            ctx.strokeStyle = cssVar('--surface', '#fff');
            ctx.lineWidth = 2;
            ctx.stroke();

            if (data.length <= 12 || i % Math.ceil(data.length / 8) === 0) {
                ctx.fillStyle = muted;
                ctx.fillText(p.label, p.x, h - padB / 2 - 2);
            }
        });
    }

    function drawBarChart(canvas, opts) {
        var data = opts.data || [];
        if (!data.length) return;
        var s = setupCanvas(canvas);
        var ctx = s.ctx, w = s.w, h = s.h;
        var padL = 46, padR = 14, padT = 16, padB = 34;
        var plotW = w - padL - padR;
        var plotH = h - padT - padB;
        var max = niceMax(Math.max.apply(null, data.map(function (d) { return d.value; })));
        var accent = cssVar('--accent-500', '#12a488');
        var muted = cssVar('--muted', '#6b7a8d');

        ctx.clearRect(0, 0, w, h);
        ctx.font = '11px Inter, Segoe UI, sans-serif';
        ctx.textBaseline = 'middle';
        ctx.strokeStyle = cssVar('--line', '#e4e9f0');
        ctx.fillStyle = muted;
        for (var i = 0; i <= 4; i++) {
            var y = padT + (plotH / 4) * i;
            ctx.beginPath(); ctx.moveTo(padL, y); ctx.lineTo(w - padR, y); ctx.stroke();
            ctx.textAlign = 'right';
            ctx.fillText(formatShort(max - (max / 4) * i), padL - 8, y);
        }

        var slot = plotW / data.length;
        var barW = Math.min(38, slot * 0.55);
        data.forEach(function (d, idx) {
            var x = padL + slot * idx + (slot - barW) / 2;
            var bh = max > 0 ? (d.value / max) * plotH : 0;
            var y = padT + plotH - bh;
            var g = ctx.createLinearGradient(0, y, 0, padT + plotH);
            g.addColorStop(0, accent);
            g.addColorStop(1, hexA(accent, 0.55));
            ctx.fillStyle = g;
            roundRect(ctx, x, y, barW, Math.max(bh, 2), 5);
            ctx.fill();
            ctx.fillStyle = muted;
            ctx.textAlign = 'center';
            ctx.fillText(d.label, x + barW / 2, h - padB / 2 - 4);
        });
    }

    function roundRect(ctx, x, y, w, h, r) {
        r = Math.min(r, w / 2, h / 2);
        ctx.beginPath();
        ctx.moveTo(x + r, y);
        ctx.arcTo(x + w, y, x + w, y + h, r);
        ctx.arcTo(x + w, y + h, x, y + h, r);
        ctx.arcTo(x, y + h, x, y, r);
        ctx.arcTo(x, y, x + w, y, r);
        ctx.closePath();
    }

    function hexA(hex, a) {
        hex = hex.trim();
        if (hex.charAt(0) !== '#') return hex;
        if (hex.length === 4) hex = '#' + hex[1] + hex[1] + hex[2] + hex[2] + hex[3] + hex[3];
        var r = parseInt(hex.substr(1, 2), 16);
        var g = parseInt(hex.substr(3, 2), 16);
        var b = parseInt(hex.substr(5, 2), 16);
        return 'rgba(' + r + ',' + g + ',' + b + ',' + a + ')';
    }

    window.POSCharts = { line: drawLineChart, bar: drawBarChart };

    // Auto-render charts defined with data-chart attributes.
    function renderAll() {
        document.querySelectorAll('canvas[data-chart]').forEach(function (canvas) {
            var raw = canvas.getAttribute('data-values');
            if (!raw) return;
            var values, labels;
            try { values = JSON.parse(raw); } catch (e) { return; }
            labels = JSON.parse(canvas.getAttribute('data-labels') || '[]');
            var data = values.map(function (v, i) { return { value: Number(v), label: labels[i] || '' }; });
            if (canvas.getAttribute('data-chart') === 'bar') drawBarChart(canvas, { data: data });
            else drawLineChart(canvas, { data: data });
        });
    }

    window.addEventListener('resize', debounce(renderAll, 150));
    document.addEventListener('DOMContentLoaded', renderAll);
    window.POSCharts.render = renderAll;

    function debounce(fn, ms) {
        var t;
        return function () {
            clearTimeout(t);
            t = setTimeout(fn, ms);
        };
    }
})();
