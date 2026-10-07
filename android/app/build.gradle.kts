plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

android {
    namespace = "ir.example.bankassistant"
    compileSdk = 35

    defaultConfig {
        applicationId = "ir.example.bankassistant"
        minSdk = 26                // floating orb: TYPE_APPLICATION_OVERLAY
        targetSdk = 35
        versionCode = 1
        versionName = "1.0"
    }

    // One fixed key so a new APK installs over the old one (sideloaded app, not Play Store).
    // For Play Store make your own upload key and keep it out of the repository.
    signingConfigs {
        create("sideload") {
            storeFile = file("../keystore/sideload.keystore")
            storePassword = "bankassistant"
            keyAlias = "sideload"
            keyPassword = "bankassistant"
        }
    }
    buildTypes {
        getByName("debug") { signingConfig = signingConfigs.getByName("sideload") }
        getByName("release") {
            isMinifyEnabled = false
            signingConfig = signingConfigs.getByName("sideload")
        }
    }
    buildFeatures { buildConfig = true }
    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions { jvmTarget = "17" }
}

dependencies {
    implementation("androidx.core:core-ktx:1.15.0")
    implementation("androidx.appcompat:appcompat:1.7.0")
    implementation("androidx.webkit:webkit:1.12.1")
    implementation("androidx.security:security-crypto:1.1.0-alpha06")
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.9.0")
}
