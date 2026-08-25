/**
 * Gráfica de área para una sola serie. Sustituye a Chart.js.
 *
 * Uso:
 *   <div class="chart-wrap">
 *     <svg data-grafica
 *          data-labels='["00","01"]'
 *          data-valores='[12,8]'
 *          data-unidad="doc"></svg>
 *     <div class="chart-tip"></div>
 *   </div>
 */
(function () {
    var NS = "http://www.w3.org/2000/svg";

    function crear(nombre, atributos) {
        var nodo = document.createElementNS(NS, nombre);
        for (var clave in atributos) {
            nodo.setAttribute(clave, atributos[clave]);
        }
        return nodo;
    }

    /** Techo "redondo" por encima del máximo, para que el eje no quede pegado. */
    function techo(maximo) {
        if (maximo <= 0) {
            return 10;
        }
        var magnitud = Math.pow(10, Math.floor(Math.log(maximo) / Math.LN10));
        return Math.ceil(maximo / (magnitud / 2)) * (magnitud / 2);
    }

    function dibujar(svg) {
        var etiquetas, valores;
        try {
            etiquetas = JSON.parse(svg.getAttribute("data-labels") || "[]");
            valores = JSON.parse(svg.getAttribute("data-valores") || "[]");
        } catch (e) {
            return;
        }
        if (valores.length < 2) {
            svg.parentNode.innerHTML = '<p class="hint">Sin datos suficientes para la gráfica.</p>';
            return;
        }

        var unidad = svg.getAttribute("data-unidad") || "";
        var W = 800, H = 170, padL = 34, padR = 8, padT = 12, padB = 22;
        var anchoUtil = W - padL - padR, altoUtil = H - padT - padB;
        var maximo = techo(Math.max.apply(null, valores));
        var ultimo = valores.length - 1;

        svg.setAttribute("viewBox", "0 0 " + W + " " + H);
        svg.setAttribute("preserveAspectRatio", "none");
        svg.style.display = "block";
        svg.style.width = "100%";
        svg.style.height = H + "px";
        svg.setAttribute("role", "img");
        svg.setAttribute(
            "aria-label",
            "Serie de " + valores.length + " puntos, máximo " + Math.max.apply(null, valores) + " " + unidad
        );

        function x(i) { return padL + (i / ultimo) * anchoUtil; }
        function y(v) { return padT + altoUtil - (v / maximo) * altoUtil; }

        [0, maximo / 2, maximo].forEach(function (v) {
            svg.appendChild(crear("line", {
                x1: padL, x2: W - padR, y1: y(v), y2: y(v),
                stroke: "var(--line)", "stroke-width": 1
            }));
            var texto = crear("text", {
                x: padL - 6, y: y(v) + 3.4, "text-anchor": "end",
                fill: "var(--muted)", "font-size": 9,
                "font-family": "IBM Plex Mono, monospace"
            });
            texto.textContent = v;
            svg.appendChild(texto);
        });

        var saltoEtiqueta = Math.max(1, Math.ceil(etiquetas.length / 8));
        etiquetas.forEach(function (etiqueta, i) {
            if (i % saltoEtiqueta !== 0) {
                return;
            }
            var texto = crear("text", {
                x: x(i), y: H - 6, "text-anchor": "middle",
                fill: "var(--muted)", "font-size": 9,
                "font-family": "IBM Plex Mono, monospace"
            });
            texto.textContent = etiqueta;
            svg.appendChild(texto);
        });

        var linea = valores.map(function (v, i) {
            return (i ? "L" : "M") + x(i).toFixed(1) + " " + y(v).toFixed(1);
        }).join(" ");
        var area = linea + " L" + x(ultimo).toFixed(1) + " " + y(0) + " L" + x(0).toFixed(1) + " " + y(0) + " Z";

        var idGradiente = "areaTalio" + Math.random().toString(36).slice(2, 8);
        var defs = crear("defs", {});
        var gradiente = crear("linearGradient", { id: idGradiente, x1: 0, y1: 0, x2: 0, y2: 1 });
        gradiente.appendChild(crear("stop", { offset: "0%", "stop-color": "var(--accent)", "stop-opacity": .16 }));
        gradiente.appendChild(crear("stop", { offset: "100%", "stop-color": "var(--accent)", "stop-opacity": .01 }));
        defs.appendChild(gradiente);
        svg.appendChild(defs);

        svg.appendChild(crear("path", { d: area, fill: "url(#" + idGradiente + ")" }));
        svg.appendChild(crear("path", {
            d: linea, fill: "none", stroke: "var(--accent)",
            "stroke-width": 2, "stroke-linejoin": "round", "stroke-linecap": "round"
        }));
        svg.appendChild(crear("circle", {
            cx: x(ultimo), cy: y(valores[ultimo]), r: 4,
            fill: "var(--accent)", stroke: "var(--surface)", "stroke-width": 2
        }));

        var cruz = crear("line", {
            x1: 0, x2: 0, y1: padT, y2: padT + altoUtil,
            stroke: "var(--line-2)", "stroke-width": 1, opacity: 0
        });
        svg.appendChild(cruz);
        var punto = crear("circle", {
            r: 4, fill: "var(--accent)", stroke: "var(--surface)", "stroke-width": 2, opacity: 0
        });
        svg.appendChild(punto);

        var contenedor = svg.parentNode;
        var globo = contenedor.querySelector(".chart-tip");
        if (!globo) {
            return;
        }

        contenedor.addEventListener("mousemove", function (evento) {
            var caja = svg.getBoundingClientRect();
            var px = ((evento.clientX - caja.left) / caja.width) * W;
            var i = Math.round(((px - padL) / anchoUtil) * ultimo);
            i = Math.min(Math.max(i, 0), ultimo);

            cruz.setAttribute("x1", x(i));
            cruz.setAttribute("x2", x(i));
            cruz.setAttribute("opacity", 1);
            punto.setAttribute("cx", x(i));
            punto.setAttribute("cy", y(valores[i]));
            punto.setAttribute("opacity", 1);

            globo.textContent = etiquetas[i] + " · " + valores[i] + (unidad ? " " + unidad : "");
            globo.style.left = ((x(i) / W) * caja.width) + "px";
            globo.style.top = ((y(valores[i]) / H) * caja.height - 8) + "px";
            globo.classList.add("on");
        });

        contenedor.addEventListener("mouseleave", function () {
            cruz.setAttribute("opacity", 0);
            punto.setAttribute("opacity", 0);
            globo.classList.remove("on");
        });
    }

    document.addEventListener("DOMContentLoaded", function () {
        document.querySelectorAll("svg[data-grafica]").forEach(dibujar);
    });
})();
