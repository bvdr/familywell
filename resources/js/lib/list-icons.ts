import {
    Activity,
    Apple,
    Armchair,
    Baby,
    Backpack,
    Bath,
    Bed,
    Bike,
    Bird,
    Bone,
    Book,
    Briefcase,
    Brush,
    Bus,
    Cake,
    Calendar,
    CalendarDays,
    Camera,
    Car,
    Carrot,
    Cat,
    CheckSquare,
    ChefHat,
    Church,
    Coffee,
    Cookie,
    CreditCard,
    Dog,
    Drill,
    Droplets,
    Dumbbell,
    FileText,
    Fish,
    Flower2,
    Fuel,
    Gamepad2,
    Gift,
    GraduationCap,
    Hammer,
    Heart,
    Home,
    Hospital,
    Key,
    Lamp,
    Landmark,
    Laptop,
    Leaf,
    Lightbulb,
    ListChecks,
    Lock,
    Luggage,
    Mail,
    Map as MapIcon,
    Milk,
    Mountain,
    Music,
    Paintbrush,
    Palmtree,
    PartyPopper,
    PawPrint,
    Phone,
    PiggyBank,
    Pill,
    Pizza,
    Plane,
    Plug,
    Receipt,
    Refrigerator,
    Sandwich,
    School,
    Scissors,
    Shirt,
    ShoppingBag,
    ShoppingCart,
    Shovel,
    Smile,
    Sofa,
    Sparkles,
    Sprout,
    Star,
    Stethoscope,
    Sun,
    Tent,
    Ticket,
    TrainFront,
    Trash2,
    TreeDeciduous,
    Trophy,
    Tv,
    Umbrella,
    Users,
    Utensils,
    Wallet,
    Warehouse,
    WashingMachine,
    Wine,
    Wrench,
    type LucideIcon,
} from 'lucide-react';

// Every icon a list can have (design j6QDy4 T3c / T3d): the key stored on the list (tasks_lists.icon, at most 20
// characters; the first ten are the original set, so lists made before keep theirs), the icon, its category in the
// icon browser, and the words that pick it for a name ("Garage" → garage, car, wrench…). Words are English plus
// the app's other languages where a family is likely to type them; a word matches the start of any word in the name.
export type ListIconDef = { key: string; icon: LucideIcon; cat: string; words: string[] };

export const ICON_CATEGORIES = ['home', 'family', 'travel', 'food', 'health', 'work', 'money', 'garden', 'fun'] as const;

export const LIST_ICON_DEFS: ListIconDef[] = [
    // The original ten.
    { key: 'list', icon: ListChecks, cat: 'work', words: ['list', 'todo', 'to-do', 'tasks', 'sarcini', 'liste', 'aufgaben'] },
    { key: 'check', icon: CheckSquare, cat: 'work', words: ['check', 'done', 'chores'] },
    { key: 'home', icon: Home, cat: 'home', words: ['home', 'house', 'household', 'casa', 'acasa', 'haus', 'maison', 'hjem', 'huis', 'hem'] },
    { key: 'work', icon: Briefcase, cat: 'work', words: ['work', 'job', 'office', 'munca', 'birou', 'arbeit', 'trabajo', 'travail', 'jobb', 'werk'] },
    {
        key: 'trip',
        icon: Plane,
        cat: 'travel',
        words: ['trip', 'travel', 'flight', 'vacation', 'holiday', 'vacanta', 'calatorie', 'reise', 'urlaub', 'viaje', 'voyage', 'reis'],
    },
    { key: 'calendar', icon: CalendarDays, cat: 'work', words: ['calendar', 'plan', 'schedule', 'weekly'] },
    { key: 'heart', icon: Heart, cat: 'family', words: ['love', 'heart', 'wedding', 'nunta', 'date'] },
    { key: 'shop', icon: ShoppingBag, cat: 'money', words: ['shop', 'shopping', 'buy', 'cumparaturi', 'einkauf', 'compras', 'courses'] },
    { key: 'star', icon: Star, cat: 'fun', words: ['star', 'important', 'goals', 'wish'] },
    { key: 'sparkles', icon: Sparkles, cat: 'home', words: ['clean', 'cleaning', 'curatenie', 'putzen', 'limpieza', 'menage'] },
    // Home
    { key: 'garage', icon: Warehouse, cat: 'home', words: ['garage', 'garaj', 'storage', 'shed', 'basement', 'beci', 'pivnita'] },
    { key: 'tools', icon: Wrench, cat: 'home', words: ['fix', 'repair', 'repairs', 'garage', 'reparatii', 'maintenance', 'diy'] },
    { key: 'hammer', icon: Hammer, cat: 'home', words: ['build', 'renovation', 'renovate', 'renovare', 'refresh', 'kitchen', 'garage', 'diy'] },
    { key: 'drill', icon: Drill, cat: 'home', words: ['drill', 'diy', 'garage', 'shelves', 'install'] },
    { key: 'sofa', icon: Sofa, cat: 'home', words: ['living', 'furniture', 'mobila', 'sofa', 'moving'] },
    { key: 'bed', icon: Bed, cat: 'home', words: ['bedroom', 'bed', 'dormitor', 'sleep', 'guest'] },
    { key: 'lamp', icon: Lamp, cat: 'home', words: ['lamp', 'decor', 'lighting'] },
    { key: 'bath', icon: Bath, cat: 'home', words: ['bath', 'bathroom', 'baie', 'bad'] },
    { key: 'laundry', icon: WashingMachine, cat: 'home', words: ['laundry', 'washing', 'rufe', 'spalat', 'wasche'] },
    { key: 'fridge', icon: Refrigerator, cat: 'home', words: ['fridge', 'kitchen', 'bucatarie', 'kuche', 'cocina', 'cuisine', 'pantry'] },
    { key: 'key', icon: Key, cat: 'home', words: ['keys', 'moving', 'mutare', 'rent', 'apartment', 'apartament', 'landlord'] },
    { key: 'trash', icon: Trash2, cat: 'home', words: ['trash', 'bins', 'gunoi', 'declutter', 'recycling'] },
    { key: 'paint', icon: Paintbrush, cat: 'home', words: ['paint', 'painting', 'zugravit', 'decorate'] },
    { key: 'plug', icon: Plug, cat: 'home', words: ['electric', 'electrician', 'utilities', 'curent'] },
    { key: 'armchair', icon: Armchair, cat: 'home', words: ['living', 'room', 'furniture'] },
    { key: 'idea', icon: Lightbulb, cat: 'home', words: ['ideas', 'idei', 'someday', 'maybe'] },
    { key: 'lock', icon: Lock, cat: 'home', words: ['private', 'secret', 'personal', 'errands'] },
    // Family
    { key: 'baby', icon: Baby, cat: 'family', words: ['baby', 'bebe', 'newborn', 'nursery'] },
    { key: 'kids', icon: Backpack, cat: 'family', words: ['kids', 'children', 'copii', 'kinder', 'ninos', 'enfants', 'barn', 'school', 'scoala'] },
    { key: 'school', icon: School, cat: 'family', words: ['school', 'scoala', 'schule', 'escuela', 'ecole', 'skole', 'homework', 'teme'] },
    { key: 'grad', icon: GraduationCap, cat: 'family', words: ['university', 'college', 'study', 'facultate', 'exam', 'examen'] },
    { key: 'dog', icon: Dog, cat: 'family', words: ['dog', 'caine', 'hund', 'perro', 'chien', 'hond', 'pets', 'vet'] },
    { key: 'cat', icon: Cat, cat: 'family', words: ['cat', 'pisica', 'katze', 'gato', 'chat', 'katt', 'pets', 'vet'] },
    { key: 'paw', icon: PawPrint, cat: 'family', words: ['pets', 'animale', 'vet', 'tiere'] },
    { key: 'bone', icon: Bone, cat: 'family', words: ['dog', 'puppy'] },
    { key: 'fish', icon: Fish, cat: 'family', words: ['fish', 'aquarium', 'peste', 'fishing'] },
    { key: 'bird', icon: Bird, cat: 'family', words: ['bird', 'pasare'] },
    {
        key: 'gift',
        icon: Gift,
        cat: 'family',
        words: ['gift', 'gifts', 'cadou', 'cadouri', 'christmas', 'craciun', 'birthday', 'presents', 'weihnachten', 'navidad', 'noel', 'jul'],
    },
    {
        key: 'cake',
        icon: Cake,
        cat: 'family',
        words: ['birthday', 'party', 'zi', 'aniversare', 'geburtstag', 'cumpleanos', 'anniversaire', 'bursdag'],
    },
    { key: 'party', icon: PartyPopper, cat: 'family', words: ['party', 'petrecere', 'celebration', 'fest'] },
    { key: 'users', icon: Users, cat: 'family', words: ['family', 'familie', 'familia', 'famille', 'guests', 'invitati'] },
    { key: 'smile', icon: Smile, cat: 'family', words: ['fun', 'happy', 'me', 'self'] },
    { key: 'church', icon: Church, cat: 'family', words: ['church', 'biserica', 'wedding', 'baptism', 'botez', 'kirche'] },
    // Travel
    { key: 'tent', icon: Tent, cat: 'travel', words: ['camping', 'camp', 'cort', 'packing', 'lake', 'outdoors', 'zelten'] },
    { key: 'map', icon: MapIcon, cat: 'travel', words: ['map', 'route', 'roadtrip', 'harta', 'explore'] },
    { key: 'luggage', icon: Luggage, cat: 'travel', words: ['packing', 'bagaj', 'luggage', 'koffer', 'trip', 'pack'] },
    { key: 'mountain', icon: Mountain, cat: 'travel', words: ['mountain', 'munte', 'hiking', 'ski', 'berge', 'tahoe'] },
    { key: 'beach', icon: Palmtree, cat: 'travel', words: ['beach', 'plaja', 'mare', 'sea', 'summer', 'strand', 'playa'] },
    { key: 'train', icon: TrainFront, cat: 'travel', words: ['train', 'tren', 'zug', 'commute'] },
    { key: 'bus', icon: Bus, cat: 'travel', words: ['bus', 'autobuz', 'commute'] },
    { key: 'car', icon: Car, cat: 'travel', words: ['car', 'masina', 'auto', 'garage', 'coche', 'voiture', 'bil', 'service'] },
    { key: 'fuel', icon: Fuel, cat: 'travel', words: ['fuel', 'gas', 'benzina', 'car', 'garage'] },
    { key: 'bike', icon: Bike, cat: 'travel', words: ['bike', 'bicicleta', 'fahrrad', 'cycling', 'garage'] },
    { key: 'ticket', icon: Ticket, cat: 'travel', words: ['tickets', 'bilete', 'concert', 'events'] },
    { key: 'camera', icon: Camera, cat: 'travel', words: ['photos', 'poze', 'camera', 'pictures'] },
    { key: 'sun', icon: Sun, cat: 'travel', words: ['summer', 'vara', 'sun', 'sommer'] },
    { key: 'umbrella', icon: Umbrella, cat: 'travel', words: ['insurance', 'asigurari', 'rain'] },
    // Food
    { key: 'utensils', icon: Utensils, cat: 'food', words: ['food', 'meals', 'mancare', 'dinner', 'cina', 'essen', 'comida', 'repas', 'recipes'] },
    { key: 'cart', icon: ShoppingCart, cat: 'food', words: ['groceries', 'grocery', 'supermarket', 'piata', 'einkaufen', 'mercado'] },
    { key: 'apple', icon: Apple, cat: 'food', words: ['fruit', 'healthy', 'fructe', 'diet'] },
    { key: 'carrot', icon: Carrot, cat: 'food', words: ['vegetables', 'legume', 'garden', 'veggies'] },
    { key: 'coffee', icon: Coffee, cat: 'food', words: ['coffee', 'cafea', 'kaffee', 'breakfast', 'mic', 'dejun'] },
    { key: 'pizza', icon: Pizza, cat: 'food', words: ['pizza', 'takeout', 'party'] },
    { key: 'chef', icon: ChefHat, cat: 'food', words: ['cooking', 'gatit', 'kochen', 'recipes', 'retete', 'meal', 'prep'] },
    { key: 'wine', icon: Wine, cat: 'food', words: ['wine', 'vin', 'drinks', 'bauturi', 'party'] },
    { key: 'cookie', icon: Cookie, cat: 'food', words: ['baking', 'snacks', 'dulciuri', 'cookies'] },
    { key: 'milk', icon: Milk, cat: 'food', words: ['milk', 'lapte', 'dairy', 'breakfast'] },
    { key: 'sandwich', icon: Sandwich, cat: 'food', words: ['lunch', 'pranz', 'lunchbox', 'school'] },
    // Health
    { key: 'pill', icon: Pill, cat: 'health', words: ['meds', 'medicine', 'medicamente', 'pills', 'pharmacy', 'farmacie'] },
    { key: 'doctor', icon: Stethoscope, cat: 'health', words: ['doctor', 'medic', 'health', 'sanatate', 'arzt', 'medico', 'dentist'] },
    { key: 'hospital', icon: Hospital, cat: 'health', words: ['hospital', 'spital', 'clinic', 'clinica'] },
    { key: 'gym', icon: Dumbbell, cat: 'health', words: ['gym', 'sala', 'fitness', 'workout', 'sport'] },
    { key: 'activity', icon: Activity, cat: 'health', words: ['health', 'sanatate', 'running', 'alergat', 'steps'] },
    { key: 'droplets', icon: Droplets, cat: 'health', words: ['water', 'apa', 'hydration', 'plants'] },
    // Work
    { key: 'laptop', icon: Laptop, cat: 'work', words: ['computer', 'laptop', 'it', 'tech', 'online'] },
    { key: 'cal', icon: Calendar, cat: 'work', words: ['appointments', 'programari', 'termine', 'meetings'] },
    { key: 'mail', icon: Mail, cat: 'work', words: ['mail', 'email', 'letters', 'posta', 'post'] },
    { key: 'phone', icon: Phone, cat: 'work', words: ['calls', 'phone', 'telefon', 'apeluri'] },
    { key: 'docs', icon: FileText, cat: 'work', words: ['papers', 'documents', 'acte', 'documente', 'paperwork', 'taxes', 'unterlagen'] },
    { key: 'book', icon: Book, cat: 'work', words: ['books', 'reading', 'carti', 'citit', 'bucher', 'libros'] },
    { key: 'scissors', icon: Scissors, cat: 'work', words: ['crafts', 'haircut', 'tuns'] },
    // Money
    { key: 'piggy', icon: PiggyBank, cat: 'money', words: ['savings', 'economii', 'sparen', 'ahorro', 'budget'] },
    { key: 'wallet', icon: Wallet, cat: 'money', words: ['money', 'bani', 'geld', 'dinero', 'argent', 'budget', 'expenses'] },
    { key: 'card', icon: CreditCard, cat: 'money', words: ['bills', 'facturi', 'payments', 'plati', 'rechnungen', 'card'] },
    { key: 'receipt', icon: Receipt, cat: 'money', words: ['receipts', 'bonuri', 'bills', 'facturi', 'taxes', 'taxe', 'expenses'] },
    {
        key: 'bank',
        icon: Landmark,
        cat: 'money',
        words: ['bank', 'banca', 'taxes', 'taxe', 'anaf', 'loan', 'credit', 'mortgage', 'insurance', 'asigurari'],
    },
    { key: 'shirt', icon: Shirt, cat: 'money', words: ['clothes', 'haine', 'kleidung', 'ropa', 'wardrobe'] },
    // Garden
    { key: 'sprout', icon: Sprout, cat: 'garden', words: ['garden', 'gradina', 'garten', 'jardin', 'hage', 'tuin', 'plants', 'plante', 'seeds'] },
    { key: 'tree', icon: TreeDeciduous, cat: 'garden', words: ['yard', 'curte', 'trees', 'garden', 'autumn'] },
    { key: 'leaf', icon: Leaf, cat: 'garden', words: ['plants', 'plante', 'leaves', 'eco', 'garden'] },
    { key: 'flower', icon: Flower2, cat: 'garden', words: ['flowers', 'flori', 'blumen', 'garden', 'spring'] },
    { key: 'shovel', icon: Shovel, cat: 'garden', words: ['garden', 'yard', 'digging', 'snow'] },
    { key: 'brush', icon: Brush, cat: 'garden', words: ['clean', 'sweep', 'yard'] },
    // Fun
    { key: 'music', icon: Music, cat: 'fun', words: ['music', 'muzica', 'musik', 'piano', 'guitar', 'lessons'] },
    { key: 'game', icon: Gamepad2, cat: 'fun', words: ['games', 'jocuri', 'spiele', 'gaming'] },
    { key: 'trophy', icon: Trophy, cat: 'fun', words: ['goals', 'obiective', 'sport', 'competition', 'challenge'] },
    { key: 'tv', icon: Tv, cat: 'fun', words: ['movies', 'filme', 'shows', 'watch', 'netflix'] },
];

const BY_KEY = new Map(LIST_ICON_DEFS.map((d) => [d.key, d.icon]));
export const listIconFor = (key: string): LucideIcon => BY_KEY.get(key) ?? ListChecks;

// Plain words of a name, lower case, accents off ("Grădină" → gradina).
const words = (s: string) =>
    s
        .normalize('NFD')
        .replace(/\p{M}/gu, '')
        .toLowerCase()
        .split(/[^\p{L}\p{N}]+/u)
        .filter((w) => w.length >= 2);

// The icons that fit a name, best first: a word of the name that starts one of the icon's words (or the other way
// round, for "Garages" / "car"). The first row of the New list screen and the browser's "Fits …" row.
export function iconsFor(name: string): ListIconDef[] {
    const ws = words(name);
    if (!ws.length) return [];
    const score = (d: ListIconDef) =>
        d.words.reduce((n, w) => n + (ws.some((x) => (x.length >= 3 && w.startsWith(x)) || x.startsWith(w)) ? 1 : 0), 0);
    return LIST_ICON_DEFS.map((d) => ({ d, s: score(d) }))
        .filter((x) => x.s > 0)
        .sort((a, b) => b.s - a.s)
        .map((x) => x.d);
}

// Search the browser by word ("car", "dog"): a word that starts with what's typed.
export function searchIcons(q: string): ListIconDef[] {
    const ws = words(q);
    if (!ws.length) return LIST_ICON_DEFS;
    return LIST_ICON_DEFS.filter((d) => ws.every((x) => d.key.startsWith(x) || d.words.some((w) => w.startsWith(x))));
}
