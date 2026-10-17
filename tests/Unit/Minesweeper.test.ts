/// <reference types="node" />
import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    adjacentMines,
    cellCount,
    chord,
    counts,
    formatDuration,
    isGame,
    isMinesweeper,
    mineCount,
    move,
    neighbors,
    plant,
    play,
    reveal,
    start,
    toggleFlag,
} from '../../resources/js/lib/minesweeper';
import type { MinesweeperGame } from '../../resources/js/types/minesweeper';

const bottomRow = Array.from({ length: 16 }, (_, index) => 96 + index);

const playing = (
    overrides: Partial<MinesweeperGame> = {},
): MinesweeperGame => ({
    status: 'playing',
    mines: bottomRow,
    revealed: [],
    flags: [],
    exploded_cell: null,
    started_at: 1_000,
    duration_ms: null,
    ...overrides,
});

void test('recognizes only a whole /minesweeper commit', () => {
    assert.ok(isMinesweeper('/minesweeper'));
    assert.ok(isMinesweeper('  /Minesweeper\n'));
    assert.ok(!isMinesweeper('minesweeper'));
    assert.ok(!isMinesweeper('/minesweeper please'));
    assert.ok(!isMinesweeper('`/minesweeper`'));
});

void test('finds the neighbors of a cell within the board', () => {
    assert.deepEqual(neighbors(0), [1, 16, 17]);
    assert.deepEqual(neighbors(17), [0, 1, 2, 16, 18, 32, 33, 34]);
    assert.deepEqual(neighbors(cellCount - 1), [94, 95, 110]);
    assert.equal(adjacentMines(17, [0, 2, 34, 50]), 3);
});

void test('plants the mines away from the first cell and its neighbors', () => {
    for (const firstCell of [0, 56, cellCount - 1]) {
        const mines = plant(firstCell);

        assert.equal(new Set(mines).size, mineCount);
        assert.ok(mines.every((mine) => mine >= 0 && mine < cellCount));
        assert.ok(
            [firstCell, ...neighbors(firstCell)].every(
                (cell) => !mines.includes(cell),
            ),
        );
        assert.deepEqual(
            mines,
            [...mines].sort((a, b) => a - b),
        );
    }
});

void test('opens up the board around cells with no mines next to them', () => {
    assert.deepEqual(reveal([0], [], [1]), [1]);
    assert.deepEqual(reveal([0], [5], [0, 5, 1]), [1, 5]);
    assert.deepEqual(
        reveal(bottomRow, [], [0]),
        Array.from({ length: 96 }, (_, cell) => cell),
    );
});

void test('starts a game with its first cell safe and revealed', () => {
    const game = start(56, 5_000);

    assert.equal(game.status, 'playing');
    assert.equal(game.started_at, 5_000);
    assert.ok(game.revealed.includes(56));
    assert.ok(!game.mines.includes(56));
});

void test('loses on a mine, timed from the start', () => {
    const game = play(playing({ revealed: [0] }), [80, 100], 13_345);

    assert.equal(game.status, 'lost');
    assert.equal(game.exploded_cell, 100);
    assert.deepEqual(game.revealed, [0]);
    assert.equal(game.duration_ms, 12_345);
});

void test('wins once the last safe cell is revealed, and drops flags it reveals', () => {
    const game = play(playing({ flags: [5, 100] }), [0], 42_000);

    assert.equal(game.status, 'won');
    assert.equal(game.duration_ms, 41_000);
    assert.deepEqual(game.flags, [100]);
});

void test('leaves a finished game as it ended', () => {
    const lost = playing({
        status: 'lost',
        exploded_cell: 96,
        duration_ms: 10,
    });

    assert.equal(play(lost, [0], 99_000), lost);
    assert.equal(toggleFlag(lost, 0), lost);
});

void test('flags and unflags hidden cells only', () => {
    const flagged = toggleFlag(playing({ revealed: [0] }), 5);

    assert.deepEqual(flagged.flags, [5]);
    assert.deepEqual(toggleFlag(flagged, 5).flags, []);
    assert.deepEqual(toggleFlag(flagged, 0).flags, [5]);
});

void test('counts the mines around revealed cells only', () => {
    const cells = counts(playing({ revealed: [0, 80] }));

    assert.equal(cells.length, cellCount);
    assert.equal(cells[0], 0);
    assert.equal(cells[80], 2);
    assert.equal(cells[1], null);
    assert.ok(counts(null).every((cell) => cell === null));
});

void test('chords a number once its mines are flagged', () => {
    const cells: (number | null)[] = Array.from(
        { length: cellCount },
        () => null,
    );
    cells[17] = 1;
    cells[1] = 1;
    cells[5] = 0;

    assert.deepEqual(chord(17, cells, new Set()), []);
    assert.deepEqual(chord(17, cells, new Set([0, 2])), []);
    assert.deepEqual(chord(17, cells, new Set([0])), [2, 16, 18, 32, 33, 34]);
    assert.deepEqual(chord(4, cells, new Set()), []);
    assert.deepEqual(chord(5, cells, new Set()), []);
});

void test('moves with the arrow keys, stopping at the edges', () => {
    assert.equal(move(0, 'ArrowUp'), 0);
    assert.equal(move(0, 'ArrowLeft'), 0);
    assert.equal(move(0, 'ArrowRight'), 1);
    assert.equal(move(0, 'ArrowDown'), 16);
    assert.equal(move(cellCount - 1, 'ArrowDown'), cellCount - 1);
    assert.equal(move(cellCount - 1, 'ArrowRight'), cellCount - 1);
    assert.equal(move(17, 'ArrowUp'), 1);
    assert.equal(move(17, 'ArrowLeft'), 16);
    assert.equal(move(0, 'Enter'), null);
});

void test('only restores a stored game that still makes sense', () => {
    assert.ok(isGame(playing()));
    assert.ok(isGame(JSON.parse(JSON.stringify(start(0, 1)))));
    assert.ok(!isGame(null));
    assert.ok(!isGame({ ...playing(), status: 'paused' }));
    assert.ok(!isGame({ ...playing(), mines: [1, 2] }));
    assert.ok(!isGame({ ...playing(), revealed: [cellCount] }));
    assert.ok(!isGame({ ...playing(), started_at: 'yesterday' }));
});

void test('formats the time of a game', () => {
    assert.equal(formatDuration(12_345, false), '12s');
    assert.equal(formatDuration(12_345, true), '12.3s');
    assert.equal(formatDuration(-50, false), '0s');
});
