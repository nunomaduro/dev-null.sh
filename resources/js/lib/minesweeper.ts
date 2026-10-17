import type { MinesweeperGame } from '@/types/minesweeper';

// A week a column, like the commit activity graph: cells are numbered row by row, from 0 at the top left.
export const rows = 7;
export const columns = 16;
export const mineCount = 16;
export const cellCount = rows * columns;

/**
 * The whole commit is the command, whatever the case. Anything else in the text is a different commit.
 */
export function isMinesweeper(body: string): boolean {
    return body.trim().toLowerCase() === '/minesweeper';
}

export function neighbors(cell: number): number[] {
    const row = Math.floor(cell / columns);
    const column = cell % columns;
    const result: number[] = [];

    for (const rowOffset of [-1, 0, 1]) {
        for (const columnOffset of [-1, 0, 1]) {
            const neighborRow = row + rowOffset;
            const neighborColumn = column + columnOffset;

            if (
                (rowOffset !== 0 || columnOffset !== 0) &&
                neighborRow >= 0 &&
                neighborRow < rows &&
                neighborColumn >= 0 &&
                neighborColumn < columns
            ) {
                result.push(neighborRow * columns + neighborColumn);
            }
        }
    }

    return result;
}

export function adjacentMines(cell: number, mines: readonly number[]): number {
    return neighbors(cell).filter((neighbor) => mines.includes(neighbor))
        .length;
}

/**
 * Mines anywhere but on the first cell revealed and around it, so the first click always opens up the board.
 */
export function plant(firstCell: number, random = Math.random): number[] {
    const safe = new Set([firstCell, ...neighbors(firstCell)]);
    const cells = Array.from({ length: cellCount }, (_, cell) => cell).filter(
        (cell) => !safe.has(cell),
    );

    for (let index = cells.length - 1; index > 0; index--) {
        const other = Math.floor(random() * (index + 1));
        [cells[index], cells[other]] = [cells[other], cells[index]];
    }

    return cells.slice(0, mineCount).sort((a, b) => a - b);
}

/**
 * Reveals the cells, and every cell around a revealed cell with no mines next to it, like the classic game.
 */
export function reveal(
    mines: readonly number[],
    revealed: readonly number[],
    cells: readonly number[],
): number[] {
    const isRevealed = new Set(revealed);
    const queue = [...cells];

    while (queue.length > 0) {
        const cell = queue.pop() as number;

        if (isRevealed.has(cell) || mines.includes(cell)) {
            continue;
        }

        isRevealed.add(cell);

        if (adjacentMines(cell, mines) === 0) {
            queue.push(...neighbors(cell));
        }
    }

    return [...isRevealed].sort((a, b) => a - b);
}

export function start(
    firstCell: number,
    now: number,
    random = Math.random,
): MinesweeperGame {
    return play(
        {
            status: 'playing',
            mines: plant(firstCell, random),
            revealed: [],
            flags: [],
            exploded_cell: null,
            started_at: now,
            duration_ms: null,
        },
        [firstCell],
        now,
    );
}

/**
 * Reveals the cells: a mine among them loses the game, and revealing the last safe cell wins it.
 */
export function play(
    game: MinesweeperGame,
    cells: readonly number[],
    now: number,
): MinesweeperGame {
    if (game.status !== 'playing') {
        return game;
    }

    const exploded = cells.find((cell) => game.mines.includes(cell));

    if (exploded !== undefined) {
        return {
            ...game,
            status: 'lost',
            exploded_cell: exploded,
            duration_ms: now - game.started_at,
        };
    }

    const revealed = reveal(game.mines, game.revealed, cells);
    const isCleared = revealed.length === cellCount - mineCount;

    return {
        ...game,
        status: isCleared ? 'won' : 'playing',
        revealed,
        flags: game.flags.filter((flag) => !revealed.includes(flag)),
        duration_ms: isCleared ? now - game.started_at : null,
    };
}

export function toggleFlag(
    game: MinesweeperGame,
    cell: number,
): MinesweeperGame {
    if (game.status !== 'playing' || game.revealed.includes(cell)) {
        return game;
    }

    return {
        ...game,
        flags: game.flags.includes(cell)
            ? game.flags.filter((flag) => flag !== cell)
            : [...game.flags, cell],
    };
}

/**
 * The board as the player sees it: the number of mines around each revealed cell, null for the ones still hidden.
 */
export function counts(game: MinesweeperGame | null): (number | null)[] {
    const cells: (number | null)[] = Array.from(
        { length: cellCount },
        () => null,
    );

    for (const cell of game?.revealed ?? []) {
        cells[cell] = adjacentMines(cell, game?.mines ?? []);
    }

    return cells;
}

/**
 * Clicking a revealed number with as many flags around it as mines reveals the rest of its neighbors, like the
 * classic game. Anything else reveals nothing.
 */
export function chord(
    cell: number,
    cells: (number | null)[],
    flags: ReadonlySet<number>,
): number[] {
    const count = cells[cell];

    if (count === null || count === 0) {
        return [];
    }

    const around = neighbors(cell);
    const flagged = around.filter((neighbor) => flags.has(neighbor));

    if (flagged.length !== count) {
        return [];
    }

    return around.filter(
        (neighbor) => cells[neighbor] === null && !flags.has(neighbor),
    );
}

/**
 * Arrow keys move through the board like through the commit activity graph, stopping at its edges.
 */
export function move(cell: number, key: string): number | null {
    const row = Math.floor(cell / columns);
    const column = cell % columns;

    switch (key) {
        case 'ArrowUp':
            return row > 0 ? cell - columns : cell;
        case 'ArrowDown':
            return row < rows - 1 ? cell + columns : cell;
        case 'ArrowLeft':
            return column > 0 ? cell - 1 : cell;
        case 'ArrowRight':
            return column < columns - 1 ? cell + 1 : cell;
        default:
            return null;
    }
}

export function formatDuration(
    milliseconds: number,
    isPrecise: boolean,
): string {
    const seconds = Math.max(0, milliseconds) / 1000;

    return isPrecise ? `${seconds.toFixed(1)}s` : `${Math.floor(seconds)}s`;
}

/**
 * A stored game only comes back when it still makes sense, so a hand-edited or outdated entry starts a fresh board.
 */
export function isGame(value: unknown): value is MinesweeperGame {
    if (typeof value !== 'object' || value === null) {
        return false;
    }

    const game = value as Record<string, unknown>;
    const isCells = (cells: unknown): cells is number[] =>
        Array.isArray(cells) &&
        cells.every(
            (cell) => Number.isInteger(cell) && cell >= 0 && cell < cellCount,
        );

    return (
        ['playing', 'won', 'lost'].includes(game.status as string) &&
        isCells(game.mines) &&
        game.mines.length === mineCount &&
        isCells(game.revealed) &&
        isCells(game.flags) &&
        typeof game.started_at === 'number' &&
        (game.duration_ms === null || typeof game.duration_ms === 'number') &&
        (game.exploded_cell === null || typeof game.exploded_cell === 'number')
    );
}
