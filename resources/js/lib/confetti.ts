type Particle = {
    x: number;
    y: number;
    vx: number;
    vy: number;
    width: number;
    height: number;
    rotation: number;
    spin: number;
    color: string;
    life: number;
    maxLife: number;
};

const colors = [
    '#f43f5e',
    '#f59e0b',
    '#22c55e',
    '#38bdf8',
    '#a78bfa',
    '#fb7185',
];

let canvas: HTMLCanvasElement | null = null;
let context: CanvasRenderingContext2D | null = null;
let particles: Particle[] = [];
let frame = 0;

function resize(): void {
    if (!canvas || !context) return;

    const ratio = Math.min(window.devicePixelRatio || 1, 2);
    canvas.width = Math.floor(window.innerWidth * ratio);
    canvas.height = Math.floor(window.innerHeight * ratio);
    context.setTransform(ratio, 0, 0, ratio, 0, 0);
}

function ensureCanvas(): void {
    if (canvas) return;

    canvas = document.createElement('canvas');
    canvas.setAttribute('aria-hidden', 'true');
    canvas.style.position = 'fixed';
    canvas.style.inset = '0';
    canvas.style.width = '100%';
    canvas.style.height = '100%';
    canvas.style.pointerEvents = 'none';
    canvas.style.zIndex = '60';
    document.body.appendChild(canvas);
    context = canvas.getContext('2d');
    resize();
    window.addEventListener('resize', resize);
}

function clearCanvas(): void {
    if (!canvas) return;

    window.removeEventListener('resize', resize);
    canvas.remove();
    canvas = null;
    context = null;
    frame = 0;
}

function tick(): void {
    if (!context) return;

    const width = window.innerWidth;
    const height = window.innerHeight;
    context.clearRect(0, 0, width, height);

    const alive: Particle[] = [];

    for (const particle of particles) {
        particle.life += 1;
        particle.vy += 0.08;
        particle.x += particle.vx;
        particle.y += particle.vy;
        particle.rotation += particle.spin;

        if (particle.life >= particle.maxLife || particle.y > height + 24) {
            continue;
        }

        context.save();
        context.translate(particle.x, particle.y);
        context.rotate(particle.rotation);
        context.globalAlpha = 1 - particle.life / particle.maxLife;
        context.fillStyle = particle.color;
        context.fillRect(
            -particle.width / 2,
            -particle.height / 2,
            particle.width,
            particle.height,
        );
        context.restore();
        alive.push(particle);
    }

    particles = alive;

    if (particles.length === 0) {
        clearCanvas();
        return;
    }

    frame = window.requestAnimationFrame(tick);
}

export function dropConfetti(): void {
    if (typeof document === 'undefined') return;
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    ensureCanvas();

    const count = 150;

    for (let index = 0; index < count; index++) {
        particles.push({
            x: Math.random() * window.innerWidth,
            y: -10 - Math.random() * 24,
            vx: (Math.random() - 0.5) * 1.6,
            vy: 1.6 + Math.random() * 2.6,
            width: 6 + Math.random() * 4,
            height: 8 + Math.random() * 6,
            rotation: Math.random() * Math.PI,
            spin: (Math.random() - 0.5) * 0.2,
            color: colors[index % colors.length],
            life: 0,
            maxLife: 110 + Math.floor(Math.random() * 40),
        });
    }

    if (frame === 0) {
        frame = window.requestAnimationFrame(tick);
    }
}
