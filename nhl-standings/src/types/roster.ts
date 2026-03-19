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

export interface RosterResponse {
    forwards: Player[];
    defensemen: Player[];
    goalies: Player[];
}
