import type { components as GeneratedComponents } from "./api";

type Id = number;
type Loose = {};

export interface User extends Loose {
  id?: Id;
  name?: string;
  email?: string;
  role?: string;
  status?: number | string;
  avatar?: string | null;
  phone?: string | null;
  email_verified_at?: string | null;
  created_at?: string;
  updated_at?: string;
}

export interface Category extends Loose {
  id: Id;
  name: string;
  slug?: string;
  description?: string | null;
  color?: string | null;
  icon?: string | null;
  parent_id?: Id | null;
  sort_order?: number;
  is_active?: boolean;
  created_at?: string;
  updated_at?: string;
}

export interface Tag extends Loose {
  [key: string]: unknown;
  id: Id;
  name: string;
  slug?: string;
  color?: string | null;
  created_at?: string;
  updated_at?: string;
}

export interface Product extends Loose {
  id: Id;
  name: string;
  slug?: string;
  sku?: string;
  description?: string | null;
  image?: string | null;
  cover_image?: string | null;
  price?: number;
  compare_price?: number | null;
  cost_price?: number | null;
  stock?: number | null;
  stock_min?: number | null;
  short_description?: string | null;
  barcode?: string | null;
  weight?: number | null;
  width?: number | null;
  height?: number | null;
  length?: number | null;
  is_active?: boolean;
  is_featured?: boolean;
  category_id?: Id | null;
  vendor_id?: string | null;
  vendor?: { id: string; name: string; slug?: string } | null;
  subscription_plan_ids?: string[];
  status?: string | number;
  created_at?: string;
  updated_at?: string;
}

export interface Post extends Loose {
  id: Id;
  title: string;
  slug?: string;
  excerpt?: string | null;
  content?: string | null;
  image?: string | null;
  cover_image?: string | null;
  status?: "draft" | "published" | "archived";
  featured?: boolean;
  category_id?: Id | null;
  tags?: Tag[];
  author_name?: string;
  category_name?: string;
  published_at?: string | null;
  created_at?: string;
  updated_at?: string;
}

export interface Order extends Loose {
  id: Id;
  status: "pending" | "processing" | "shipped" | "delivered" | "cancelled" | "refunded" | string;
  order_number?: string;
  customer?: User | null;
  user_name?: string;
  customer_email?: string;
  payment_status?: string;
  shipping_address?: string;
  billing_address?: string;
  notes?: string;
  total?: number;
  items?: OrderItem[];
  created_at?: string;
  updated_at?: string;
}

export interface OrderItem extends Loose {
  id?: Id;
  product_id?: Id;
  product?: Product;
  name?: string;
  product_name?: string;
  quantity?: number;
  price?: number;
  subtotal?: number;
  unit_price?: number;
}

export interface Settings extends Loose {
  app_name?: string;
  app_description?: string;
  language?: string;
  timezone?: string;
  currency?: string;
  pagination_per_page?: number;
  maintenance_mode?: boolean;
  email_notifications?: boolean;
}

export interface DashboardStatsResponse extends Loose {
  users?: number;
  products?: number;
  orders?: number;
  revenue?: number;
  [key: string]: unknown;
}

export interface ActivityItem extends Loose {
  id?: Id | string;
  type?: string;
  message?: string;
  created_at?: string;
}

export interface ApiStatus extends Loose {
  status?: string;
  version?: string;
  database?: string;
  [key: string]: unknown;
}

export interface LoginRequest extends Loose {
  email: string;
  password: string;
  "cf-turnstile-response"?: string;
}

export interface RegisterRequest extends Loose {
  name: string;
  email: string;
  password: string;
  password_confirmation?: string;
  "cf-turnstile-response"?: string;
}

export interface ResetPasswordRequest extends Loose {
  token: string;
  password: string;
  password_confirmation?: string;
}

export interface VerifyEmailRequest extends Loose {
  token?: string;
}

export interface AuthTokenResponse extends Loose {
  success?: boolean;
  message?: string;
  data?: {
    token: string;
    refresh_token?: string;
    token_type?: string;
    expires_in?: number;
    user?: User;
  };
}

type Create<T> = Omit<T, "id" | "created_at" | "updated_at">;
type Update<T> = Partial<Create<T>>;
type Success<T> = GeneratedComponents["schemas"]["SuccessEnvelope"] & { data: T };
type NullSuccess = Success<null>;

export type LegacySchemas = {
  User: User;
  Category: Category;
  Tag: Tag;
  Product: Product;
  Post: Post;
  Order: Order;
  Settings: Settings;
  DashboardStatsResponse: DashboardStatsResponse;
  ActivityItem: ActivityItem;
  ApiStatus: ApiStatus;
  LoginRequest: LoginRequest;
  RegisterRequest: RegisterRequest;
  ResetPasswordRequest: ResetPasswordRequest;
  VerifyEmailRequest: VerifyEmailRequest;
  AuthTokenResponse: AuthTokenResponse;
  CreateUserRequest: Create<User>;
  UpdateUserRequest: Update<User>;
  CreateCategoryRequest: Create<Category>;
  UpdateCategoryRequest: Update<Category>;
  CreateTagRequest: Create<Tag>;
  UpdateTagRequest: Update<Tag>;
  CreateProductRequest: Create<Product>;
  UpdateProductRequest: Update<Product>;
  CreatePostRequest: Create<Post>;
  UpdatePostRequest: Update<Post>;
  CreateOrderRequest: Create<Order>;
  UpdateOrderRequest: Update<Order>;
  UpdateProfileRequest: Update<User>;
  ChangePasswordRequest: { current_password: string; password?: string; new_password?: string; password_confirmation?: string; new_password_confirmation?: string };
  UpdateSettingsRequest: Partial<Settings>;
  SuccessResponse_User: Success<User>;
  SuccessResponse_Category: Success<Category>;
  SuccessResponse_Tag: Success<Tag>;
  SuccessResponse_Product: Success<Product>;
  SuccessResponse_Post: Success<Post>;
  SuccessResponse_Order: Success<Order>;
  SuccessResponse_Settings: Success<Settings>;
  SuccessResponse_null: NullSuccess;
};

export type components = Omit<GeneratedComponents, "schemas"> & {
  schemas: GeneratedComponents["schemas"] & LegacySchemas;
};
