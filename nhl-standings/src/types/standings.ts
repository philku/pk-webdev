// TypeScript Interface für einen Eintrag in der NHL-Standings-Tabelle.
// Die NHL API gibt lokalisierte Namen als { default: string, fr?: string } zurück.
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

// Filter-Typen für die UI
export type ConferenceFilter = 'all' | 'Eastern' | 'Western';
