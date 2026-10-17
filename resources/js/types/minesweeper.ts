export type MinesweeperGameStatus = 'playing' | 'won' | 'lost';

export type MinesweeperGame = {
    status: MinesweeperGameStatus;
    mines: number[];
    revealed: number[];
    flags: number[];
    exploded_cell: number | null;
    started_at: number;
    duration_ms: number | null;
};

export type MinesweeperRecord = {
    game: MinesweeperGame | null;
    best_ms: number | null;
    played: number;
};
