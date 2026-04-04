export interface TeamStanding {
    teamName: { default: string };
    teamCommonName: { default: string };
    teamAbbrev: { default: string };
    teamLogo: string;
    conferenceName: string;
    divisionName: string;
    gamesPlayed: number;
    wins: number;
    losses: number;
    otLosses: number;
    points: number;
    goalFor: number;
    goalAgainst: number;
    goalDifferential: number;
    streakCode: string;
    streakCount: number;
    leagueSequence: number;
}

// API-Response von /v1/standings/now
export interface StandingsResponse {
    standings: TeamStanding[];
}

export type ConferenceFilter = 'all' | 'Eastern' | 'Western';

export type SortKey = 'points' | 'wins' | 'losses' | 'gamesPlayed' | 'goalDifferential';

export type SortDirection = 'asc' | 'desc';
