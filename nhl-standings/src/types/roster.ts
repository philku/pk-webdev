// TypeScript Interface für einen NHL-Spieler im Roster.
export interface Player {
    id: number;
    headshot: string;
    firstName: { default: string };
    lastName: { default: string };
    sweaterNumber: number;
    positionCode: string;
    shootsCatches: string;
    heightInCentimeters: number;
    weightInKilograms: number;
    birthDate: string;
    birthCity: { default: string };
    birthCountry: string;
}

// API-Response von /v1/roster/{teamAbbrev}/current
// Spieler sind bereits nach Position gruppiert.
export interface RosterResponse {
    forwards: Player[];
    defensemen: Player[];
    goalies: Player[];
}
