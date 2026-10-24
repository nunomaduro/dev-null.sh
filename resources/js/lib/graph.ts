export type Point = { x: number; y: number };

/**
 * A node of the commit graph, measured where it is drawn: its centre and the radius of its outer ring.
 *
 * A node follows `after` on the same trunk, like the thread rail, and branches off `parent` when it is a reply to it.
 */
export type GraphNode = Point & {
    id: string;
    radius: number;
    lane: string;
    after?: string;
    parent?: string;
    dashed?: boolean;
    dimmed?: boolean;
    /** On its way out, like an end dot scaling out as the thread goes on below it: linked to nothing, it only keeps its cut-out. */
    isLeaving?: boolean;
    /** Boxes the node carries past its ring, such as a LIVE pill on its edge, which lines pass behind too. */
    cutouts?: (Point & { width: number; height: number })[];
};

export type GraphEdge = {
    id: string;
    from: string;
    to: string;
    d: string;
    start: Point;
    end: Point;
    /** How far the line runs straight down from its start before it turns, if it does. */
    drop: number;
    /** The lane of the node the line leaves, which it keeps to the end. */
    lane: string;
    dashed: boolean;
    /** How far into its dashes the line starts: a branch picks them up where the column above it left them. */
    dashOffset: number;
    dimmed: boolean;
};

const cornerRadius = 12;

/**
 * Every line between the nodes, each one a single path so its dashes run on without a seam. Lines go
 * from centre to centre: the canvas cuts the nodes out, so a line always meets a ring exactly and never crosses one.
 *
 * A trunk still drawing itself in, among `drawing`, doesn't go on below the replies yet: they keep their dashed column
 * until it has covered it.
 */
export function graphEdges(
    nodes: GraphNode[],
    drawing: ReadonlySet<string> = new Set(),
): GraphEdge[] {
    const byId = new Map(nodes.map((node) => [node.id, node]));
    const edges: GraphEdge[] = [];

    for (const node of nodes) {
        const previous =
            node.after === undefined ? undefined : byId.get(node.after);

        if (previous) {
            edges.push(trunk(previous, node));
        }

        const parent =
            node.parent === undefined ? undefined : byId.get(node.parent);

        if (parent) {
            const continues = nodes.some(
                (other) =>
                    other.after === parent.id &&
                    other.y > node.y &&
                    !drawing.has(trunkId(parent, other)),
            );
            const above = nodes
                .filter(
                    (other) => other.parent === parent.id && other.y < node.y,
                )
                .reduce<GraphNode | undefined>(
                    (closest, other) =>
                        closest === undefined || other.y > closest.y
                            ? other
                            : closest,
                    undefined,
                );

            edges.push(
                branch(
                    parent,
                    node,
                    continues
                        ? undefined
                        : above === undefined
                          ? parent.y
                          : cornerOf(parent, above),
                ),
            );
        }
    }

    return edges;
}

/**
 * An animation running on a node or around it: how long it has left, and, while it waits to start, how long until it
 * does. A node that trails its line, like an end dot, only pops once the line is drawn.
 */
export type NodeAnimation = {
    name: string;
    remaining: number;
    isWaiting?: boolean;
    startsIn?: number;
    trails?: boolean;
};

/**
 * How long until a node waiting to scale in starts, like a chained commit waiting for the trunk to reach it: the line
 * bridging to it then lands on it as it shows, as the commit's body ends fading in.
 */
export function nodeStartsIn(animations: NodeAnimation[]): number {
    return Math.max(
        0,
        ...animations
            .filter(
                (animation) =>
                    animation.name === 'node-in' &&
                    animation.isWaiting &&
                    !animation.trails,
            )
            .map((animation) => animation.startsIn ?? 0),
    );
}

/**
 * How long a new line waits before drawing itself in: a node that scales in overshoots its ring, so the line would
 * run over it, and so does a node still waiting to scale in, like a chained commit waiting for the trunk to reach it.
 * A node that trails its line, like an end dot that only pops once its line is drawn, doesn't hold the line back.
 */
export function entranceDelay(animations: NodeAnimation[]): number {
    return Math.max(
        0,
        ...animations
            .filter(
                (animation) =>
                    animation.name === 'node-in' &&
                    !(animation.isWaiting && animation.trails),
            )
            .map((animation) => animation.remaining),
    );
}

/**
 * Whether a line shows in the viewport, given how far down the viewport its canvas starts. A line that shows up off
 * screen, like those of cards loaded ahead of the scroll, has no one to draw itself in for.
 */
export function isOnScreen(
    edge: GraphEdge,
    canvasTop: number,
    viewportHeight: number,
): boolean {
    const top = canvasTop + Math.min(edge.start.y, edge.end.y);
    const bottom = canvasTop + Math.max(edge.start.y, edge.end.y);

    return bottom >= 0 && top <= viewportHeight;
}

/**
 * How much of a new line, from its start, is already painted by a line that just left the same node from the same
 * place, like the line to a "more replies" dot once the replies it stood for take its place: that stretch stays, and
 * only the rest draws itself in.
 */
export function drawnStretch(edge: GraphEdge, gone: GraphEdge[]): number {
    return Math.max(
        0,
        ...gone
            .filter(
                (previous) =>
                    previous.from === edge.from &&
                    previous.start.x === edge.start.x &&
                    previous.start.y === edge.start.y,
            )
            .map((previous) => Math.min(previous.drop, edge.drop)),
    );
}

/**
 * How long a commit chained under the last one of a thread waits to scale in, as its body fades in and the trunk draws
 * itself to it.
 */
export const bridgeDuration = 300;

/**
 * How a line draws itself in: after how long, from how far along, in px, as the stretch before is already painted, and
 * in how long when it bridges that stretch to a node waiting for it (null for the usual draw).
 */
export type Draw = { delay: number; from: number; duration: number | null };

/**
 * How a new line draws itself in. A new line waits for the nodes at its ends to scale in, so it doesn't run over them
 * as they overshoot, and a line taking over the stretch a line that just left painted draws on from it. One doing both,
 * like the trunk to a commit chained under the last one of a thread, draws on right away instead, at an even pace,
 * landing on the commit as it starts scaling in, in `startsIn`: the commit never stands apart from the thread.
 */
export function entranceDraw(
    edge: GraphEdge,
    gone: GraphEdge[],
    delay: number,
    startsIn: number,
): Draw {
    const from = drawnStretch(edge, gone);

    return from > 0 && delay > 0
        ? { delay: 0, from, duration: startsIn > 0 ? startsIn : bridgeDuration }
        : { delay, from, duration: null };
}

/**
 * What places a node in the graph, so it has to be registered and measured again when it changes. Dimming only
 * changes how its lines look.
 */
export function nodeIdentity(spec: {
    id: string;
    lane: string;
    after?: string;
    parent?: string;
    dashed?: boolean;
    ring?: number;
    dimmed?: boolean;
    isLeaving?: boolean;
}): string {
    // Kept as given, since a missing `dashed` lets a line take its other node's.
    return JSON.stringify({
        id: spec.id,
        lane: spec.lane,
        after: spec.after,
        parent: spec.parent,
        dashed: spec.dashed,
        ring: spec.ring,
        isLeaving: spec.isLeaving,
    });
}

/**
 * A node's id within the card that shows it. A commit can show twice in one canvas, like a quote listed under the
 * commit it quotes and as a card of its own, and each place needs a node of its own.
 */
export function scopedNodeId(scope: string | null, id: string): string {
    return scope === null ? id : `${scope}/${id}`;
}

/** A row on its way out, like a deleted card collapsing: the nodes it holds and how faded it is. */
export type LeavingRow = { ids: Set<string>; opacity: number };

/**
 * How a line shows while a row it touches leaves: as faded as the faintest of them, and clipped to the row (by its
 * index) when the line lies within it, as the rail inside the row was.
 */
export function leavingOf(
    edge: GraphEdge,
    rows: LeavingRow[],
): { opacity: number; clip?: number } | null {
    const touched = rows.filter(
        (row) => row.ids.has(edge.from) || row.ids.has(edge.to),
    );

    if (touched.length === 0) {
        return null;
    }

    const opacity = Math.min(...touched.map((row) => row.opacity));
    const clip = rows.findIndex(
        (row) => row.ids.has(edge.from) && row.ids.has(edge.to),
    );

    return clip === -1 ? { opacity } : { opacity, clip };
}

export function sameNodes(a: GraphNode[], b: GraphNode[]): boolean {
    return JSON.stringify(a) === JSON.stringify(b);
}

function trunkId(from: GraphNode, to: GraphNode): string {
    return `${from.id}->${to.id}`;
}

function trunk(from: GraphNode, to: GraphNode): GraphEdge {
    return {
        id: trunkId(from, to),
        from: from.id,
        to: to.id,
        d:
            from.x === to.x
                ? `M ${from.x} ${from.y} V ${to.y}`
                : `M ${from.x} ${from.y} L ${to.x} ${to.y}`,
        start: { x: from.x, y: from.y },
        end: { x: to.x, y: to.y },
        drop: from.x === to.x ? Math.max(0, to.y - from.y) : 0,
        lane: from.lane,
        dashed: to.dashed ?? from.dashed ?? false,
        dashOffset: 0,
        dimmed: isDimmed(from, to),
    };
}

function cornerRadiusOf(from: GraphNode, to: GraphNode): number {
    return Math.max(0, Math.min(cornerRadius, to.x - from.x, to.y - from.y));
}

/** Where the branch to a reply leaves its parent's column and turns toward the reply. */
function cornerOf(from: GraphNode, to: GraphNode): number {
    return to.y - cornerRadiusOf(from, to);
}

/**
 * Every reply hangs off its parent's column with a rounded corner, the column running down from where the reply
 * above turned (from the parent for the first one), so the branches share it without overlapping. When the parent's
 * trunk goes on below the reply, the branch only starts at its corner, so it doesn't draw a second line over the
 * trunk. A node on the column itself, like a "more replies" dot, takes a straight line.
 *
 * A reply has a single parent, so its branch is named after the reply alone: when the parent's end dot gives way to the
 * commit, as the thread goes on below it, the branch stays the line already drawn instead of drawing itself in again.
 */
function branch(
    from: GraphNode,
    to: GraphNode,
    top: number | undefined,
): GraphEdge {
    const radius = cornerRadiusOf(from, to);
    const corner = to.y - radius;
    const start = { x: from.x, y: top ?? corner };
    const turn = `Q ${from.x} ${to.y} ${from.x + radius} ${to.y} H ${to.x}`;
    const d =
        to.x === from.x
            ? `M ${from.x} ${start.y} V ${to.y}`
            : top === undefined
              ? `M ${from.x} ${corner} ${turn}`
              : `M ${from.x} ${top} V ${corner} ${turn}`;

    return {
        id: `branch:${to.id}`,
        from: from.id,
        to: to.id,
        d,
        start,
        end: { x: to.x, y: to.y },
        drop: Math.max(0, (to.x === from.x ? to.y : corner) - start.y),
        lane: from.lane,
        dashed: true,
        dashOffset: start.y - from.y,
        dimmed: isDimmed(from, to),
    };
}

function isDimmed(from: GraphNode, to: GraphNode): boolean {
    return Boolean(from.dimmed || to.dimmed);
}
