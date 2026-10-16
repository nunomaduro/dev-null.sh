export type ActivityScale = 'day' | 'week' | 'month' | 'year';

export type ActivityBucket = {
    date: string;
    count: number;
    // A shade picked elsewhere, such as GitHub's, instead of one relative to the busiest bucket.
    level?: number;
};

export type Activity = {
    scale: ActivityScale;
    buckets: ActivityBucket[];
};
